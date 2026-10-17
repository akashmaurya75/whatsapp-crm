<?php

declare(strict_types=1);

namespace App\Domain\Templates;

use App\Core\Database;
use App\Domain\Contacts\PhoneNormalizer;
use App\Domain\ValidationException;
use App\Tenancy\TenantContext;
use App\WhatsApp\AccountRepository;
use App\WhatsApp\NormalizedEvent;
use App\WhatsApp\ProviderFactory;
use App\WhatsApp\TemplateProvider;

/**
 * WhatsApp message templates: validation against Meta's rules, submission for approval,
 * sync, status updates, previews and send-time parameters.
 *
 * Variables ("slots") a template needs at send time:
 *   header_1      text header {{1}}
 *   body_1..n     body {{1}}..{{n}}
 *   button_<i>    dynamic URL suffix for URL button i ("https://x.com/track/{{1}}")
 * plus a media file/link when the header is an image, video or document.
 */
final class TemplateService
{
    public const CATEGORIES = ['marketing' => 'Marketing', 'utility' => 'Utility'];
    public const HEADER_TYPES = ['none' => 'None', 'text' => 'Text', 'image' => 'Image', 'video' => 'Video', 'document' => 'Document'];
    public const LANGUAGES = [
        'en' => 'English', 'en_US' => 'English (US)', 'en_GB' => 'English (UK)', 'hi' => 'Hindi', 'mr' => 'Marathi',
        'gu' => 'Gujarati', 'ta' => 'Tamil', 'te' => 'Telugu', 'kn' => 'Kannada', 'ml' => 'Malayalam', 'bn' => 'Bengali',
        'pa' => 'Punjabi', 'ur' => 'Urdu', 'ar' => 'Arabic', 'es' => 'Spanish', 'pt_BR' => 'Portuguese (BR)', 'fr' => 'French',
        'de' => 'German', 'id' => 'Indonesian', 'ms' => 'Malay', 'it' => 'Italian', 'ru' => 'Russian', 'tr' => 'Turkish',
    ];
    public const MEDIA_LIMITS = [
        'image' => ['mimes' => ['image/jpeg', 'image/png'], 'max' => 5 * 1024 * 1024],
        'video' => ['mimes' => ['video/mp4', 'video/3gpp'], 'max' => 16 * 1024 * 1024],
        'document' => ['mimes' => ['application/pdf'], 'max' => 16 * 1024 * 1024],
    ];
    /** Statuses in which a template may be sent. */
    public const SENDABLE = ['approved'];

    public readonly TemplateRepository $repo;

    public function __construct(private Database $db, private TenantContext $tenant, private ?ProviderFactory $providers = null)
    {
        $this->repo = new TemplateRepository($db, $tenant);
    }

    // ------------------------------------------------------------------ parsing helpers

    /** @return list<int> variable numbers found, e.g. [1,2] */
    public static function vars(string $text): array
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $m);
        return array_map('intval', $m[1]);
    }

    /** @return list<array{type:string,text:string,value:string}> */
    public static function buttons(array $t): array
    {
        $b = json_decode((string) ($t['buttons'] ?? '[]'), true);
        return is_array($b) ? array_values($b) : [];
    }

    /** @return list<string> */
    public static function bodySamples(array $t): array
    {
        $s = json_decode((string) ($t['body_samples'] ?? '[]'), true);
        return is_array($s) ? array_values(array_map('strval', $s)) : [];
    }

    /** Send-time slots: key => human label. */
    public static function slots(array $t): array
    {
        $slots = [];
        if (($t['header_type'] ?? '') === 'text' && self::vars((string) $t['header_text']) !== []) {
            $slots['header_1'] = 'Header {{1}}';
        }
        foreach (array_unique(self::vars((string) $t['body'])) as $n) {
            $slots['body_' . $n] = 'Body {{' . $n . '}}';
        }
        foreach (self::buttons($t) as $i => $b) {
            if ($b['type'] === 'url' && self::vars($b['value']) !== []) {
                $slots['button_' . $i] = 'Button "' . $b['text'] . '" link ending';
            }
        }
        return $slots;
    }

    public static function needsMedia(array $t): bool
    {
        return in_array($t['header_type'] ?? 'none', ['image', 'video', 'document'], true);
    }

    /** Why a template can't be sent (null = sendable). */
    public static function unsendableReason(array $t): ?string
    {
        if (!in_array($t['status'], self::SENDABLE, true)) {
            return 'Template is ' . $t['status'] . ', not approved.';
        }
        if (($t['category'] ?? '') === 'authentication') {
            return 'Authentication (OTP) templates can only be sent through the API integration.';
        }
        foreach (self::buttons($t) as $b) {
            if ($b['type'] === 'other') {
                return 'This template uses a button type that is not supported here yet (' . $b['text'] . ').';
            }
        }
        return null;
    }

    /** Fill {{n}} placeholders for previews. Missing values show as [n]. */
    public static function fill(string $text, array $values, string $prefix = 'body_'): string
    {
        return (string) preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', static function ($m) use ($values, $prefix) {
            $v = $values[$prefix . $m[1]] ?? null;
            return $v !== null && $v !== '' ? (string) $v : '[' . $m[1] . ']';
        }, $text);
    }

    /** Plain-text rendering stored on the message for the inbox. */
    public static function render(array $t, array $values): string
    {
        $parts = [];
        if (($t['header_type'] ?? '') === 'text' && (string) $t['header_text'] !== '') {
            $parts[] = '*' . self::fill((string) $t['header_text'], $values, 'header_') . '*';
        } elseif (self::needsMedia($t)) {
            $parts[] = '[' . $t['header_type'] . ']';
        }
        $parts[] = self::fill((string) $t['body'], $values);
        if ((string) ($t['footer'] ?? '') !== '') {
            $parts[] = '_' . $t['footer'] . '_';
        }
        $btns = array_map(static fn ($b) => '[' . $b['text'] . ']', self::buttons($t));
        if ($btns !== []) {
            $parts[] = implode(' ', $btns);
        }
        return implode("\n\n", $parts);
    }

    /**
     * Build Meta send-time components.
     * @param array<string,string> $values slot => value
     * @param array{link?:string,id?:string}|null $media header media
     */
    public static function sendComponents(array $t, array $values, ?array $media = null): array
    {
        $components = [];
        if (self::needsMedia($t)) {
            if ($media === null || (empty($media['link']) && empty($media['id']))) {
                throw new ValidationException(['media' => 'This template has a ' . $t['header_type'] . ' header: provide the file or a public https link.']);
            }
            $obj = !empty($media['id']) ? ['id' => (string) $media['id']] : ['link' => (string) $media['link']];
            if ($t['header_type'] === 'document') {
                $obj['filename'] = (string) ($media['filename'] ?? 'document.pdf');
            }
            $components[] = ['type' => 'header', 'parameters' => [['type' => $t['header_type'], $t['header_type'] => $obj]]];
        } elseif (isset(self::slots($t)['header_1'])) {
            $components[] = ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => self::requireValue($values, 'header_1')]]];
        }
        $bodyVars = array_unique(self::vars((string) $t['body']));
        sort($bodyVars);
        if ($bodyVars !== []) {
            $components[] = ['type' => 'body', 'parameters' => array_map(
                static fn (int $n) => ['type' => 'text', 'text' => self::requireValue($values, 'body_' . $n)],
                $bodyVars
            )];
        }
        foreach (self::buttons($t) as $i => $b) {
            if ($b['type'] === 'url' && self::vars($b['value']) !== []) {
                $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => (string) $i,
                    'parameters' => [['type' => 'text', 'text' => self::requireValue($values, 'button_' . $i)]]];
            }
        }
        return $components;
    }

    private static function requireValue(array $values, string $slot): string
    {
        $v = trim((string) ($values[$slot] ?? ''));
        if ($v === '') {
            throw new ValidationException([$slot => "A value is required for $slot (WhatsApp rejects empty variables)."]);
        }
        // WhatsApp rejects newlines/tabs and 4+ consecutive spaces in parameters.
        $v = (string) preg_replace(['/[\r\n\t]+/', '/ {4,}/'], [' ', '   '], $v);
        return mb_substr($v, 0, 1024);
    }

    // ------------------------------------------------------------------ variable mapping

    public const SOURCES = [
        'first_name' => 'Contact first name', 'name' => 'Contact full name', 'phone' => 'Contact phone',
        'email' => 'Contact email', 'company' => 'Contact company', 'city' => 'Contact city', 'static' => 'Fixed text',
    ];

    /**
     * Normalise a mapping posted from a form: slot => {source, value, fallback}.
     * @return array<string,array{source:string,value:string,fallback:string}>
     */
    public static function cleanMap(array $t, array $raw): array
    {
        $map = [];
        foreach (array_keys(self::slots($t)) as $slot) {
            $r = (array) ($raw[$slot] ?? []);
            $source = (string) ($r['source'] ?? 'static');
            $map[$slot] = [
                'source' => isset(self::SOURCES[$source]) ? $source : 'static',
                'value' => mb_substr(trim((string) ($r['value'] ?? '')), 0, 500),
                'fallback' => mb_substr(trim((string) ($r['fallback'] ?? '')), 0, 100),
            ];
        }
        return $map;
    }

    /** @return array<string,string> field => error for incomplete mappings */
    public static function mapErrors(array $t, array $map): array
    {
        $e = [];
        foreach (self::slots($t) as $slot => $label) {
            $m = $map[$slot] ?? null;
            if ($m === null || ($m['source'] === 'static' && $m['value'] === '') || ($m['source'] !== 'static' && $m['fallback'] === '')) {
                $e['map_' . $slot] = $label . ': ' . (($m['source'] ?? 'static') === 'static' ? 'enter the text to use.' : 'enter a fallback for contacts without this detail.');
            }
        }
        return $e;
    }

    /** Resolve slot values for one contact. */
    public static function resolve(array $map, array $contact): array
    {
        $values = [];
        foreach ($map as $slot => $m) {
            $v = match ($m['source']) {
                'static' => $m['value'],
                'first_name' => trim(explode(' ', trim((string) ($contact['name'] ?? '')))[0] ?? ''),
                'name' => (string) ($contact['name'] ?? ''),
                'phone' => (string) ($contact['phone_e164'] ?? ''),
                'email' => (string) ($contact['email'] ?? ''),
                'company' => (string) ($contact['company'] ?? ''),
                'city' => (string) ($contact['city'] ?? ''),
                default => '',
            };
            $values[$slot] = trim($v) !== '' ? trim($v) : $m['fallback'];
        }
        return $values;
    }

    /** Sensible default mapping: first body variable → first name, others → their sample text. */
    public static function defaultMap(array $t): array
    {
        $samples = self::bodySamples($t);
        $map = [];
        foreach (array_keys(self::slots($t)) as $slot) {
            if ($slot === 'body_1' || $slot === 'header_1') {
                $map[$slot] = ['source' => 'first_name', 'value' => '', 'fallback' => 'there'];
            } else {
                $n = (int) substr($slot, strrpos($slot, '_') + 1);
                $map[$slot] = ['source' => 'static', 'value' => str_starts_with($slot, 'body_') ? (string) ($samples[$n - 1] ?? '') : '', 'fallback' => ''];
            }
        }
        return $map;
    }

    // ------------------------------------------------------------------ validation & Meta payload

    /**
     * @param array<string,mixed> $in raw form input
     * @return array<string,mixed> columns for message_templates
     */
    public function validate(array $in, ?int $ignoreId = null): array
    {
        $e = [];
        $s = static fn (string $k) => trim((string) ($in[$k] ?? ''));
        $accountId = (int) ($in['whatsapp_account_id'] ?? 0);
        $account = $accountId > 0 ? (new AccountRepository($this->db, $this->tenant))->find($accountId) : null;
        if ($account === null || $account['provider'] !== 'meta_cloud') {
            $e['whatsapp_account_id'] = 'Choose a Meta Cloud API account (templates are a Meta feature).';
        }
        $name = strtolower($s('name'));
        if (!preg_match('/^[a-z0-9_]{1,191}$/', $name)) {
            $e['name'] = 'Use lowercase letters, numbers and underscores only, e.g. order_update.';
        }
        $language = $s('language');
        if (!isset(self::LANGUAGES[$language])) {
            $e['language'] = 'Choose a language.';
        }
        $category = strtolower($s('category'));
        if (!isset(self::CATEGORIES[$category])) {
            $e['category'] = 'Choose Marketing or Utility.';
        }
        if (!isset($e['name'], $e['language']) && $account !== null) {
            $dup = $this->db->scalar('SELECT id FROM message_templates WHERE whatsapp_account_id = ? AND name = ? AND language = ? AND tenant_id = ?',
                [$account['id'], $name, $language, $this->tenant->id()]);
            if ($dup !== null && (int) $dup !== $ignoreId) {
                $e['name'] = 'A template with this name and language already exists on this number.';
            }
        }

        $headerType = $s('header_type') ?: 'none';
        if (!isset(self::HEADER_TYPES[$headerType])) {
            $headerType = 'none';
        }
        $headerText = $headerType === 'text' ? $s('header_text') : '';
        $headerSample = '';
        if ($headerType === 'text') {
            $hv = self::vars($headerText);
            if ($headerText === '' || mb_strlen($headerText) > 60) {
                $e['header_text'] = 'Header text is required (max 60 characters).';
            } elseif (count($hv) > 1 || ($hv !== [] && $hv !== [1])) {
                $e['header_text'] = 'A text header can contain at most one variable: {{1}}.';
            } elseif ($hv !== []) {
                $headerSample = $s('header_sample');
                if ($headerSample === '') {
                    $e['header_sample'] = 'Give an example value for the header variable (Meta requires samples).';
                }
            }
            if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]|\*|_|~|\n/u', $headerText)) {
                $e['header_text'] = 'Header text cannot contain emojis, formatting characters or line breaks.';
            }
        }

        $body = str_replace("\r\n", "\n", (string) ($in['body'] ?? ''));
        $body = trim($body);
        $vars = self::vars($body);
        $samples = [];
        if ($body === '' || mb_strlen($body) > 1024) {
            $e['body'] = 'Message body is required (max 1024 characters).';
        } else {
            $unique = array_values(array_unique($vars));
            $expected = $unique === [] ? [] : range(1, max($unique));
            sort($unique);
            if ($unique !== $expected) {
                $e['body'] = 'Variables must be numbered in order without gaps: {{1}}, {{2}}, {{3}}…';
            } elseif (preg_match('/\{\{(?!\s*\d+\s*\}\})/', $body)) {
                $e['body'] = 'Variables must look like {{1}} — check for typos.';
            } elseif ($vars !== [] && (preg_match('/^\s*\{\{\s*\d+\s*\}\}/', $body) || preg_match('/\{\{\s*\d+\s*\}\}[\s.!?]*$/', $body))) {
                $e['body'] = 'Meta does not allow a variable at the very start or end of the message. Add some words around it.';
            } elseif ($unique !== [] && mb_strlen(trim((string) preg_replace('/\{\{\s*\d+\s*\}\}/', '', $body))) < 4 * count($unique)) {
                $e['body'] = 'Too many variables for the amount of text. Add more fixed wording.';
            } elseif (preg_match('/\n{3,}/', $body)) {
                $e['body'] = 'Remove extra blank lines (max one empty line in a row).';
            }
            $rawSamples = (array) ($in['body_samples'] ?? []);
            foreach ($unique as $n) {
                $v = trim((string) ($rawSamples[$n] ?? ''));
                if ($v === '') {
                    $e['body_samples'] = 'Give an example value for every variable (Meta reviews with these).';
                }
                $samples[$n] = mb_substr($v, 0, 200);
            }
        }

        $footer = $s('footer');
        if (mb_strlen($footer) > 60 || self::vars($footer) !== []) {
            $e['footer'] = 'Footer: max 60 characters, no variables.';
        }

        $buttons = [];
        $counts = ['quick_reply' => 0, 'url' => 0, 'phone' => 0];
        foreach ((array) ($in['buttons'] ?? []) as $i => $b) {
            $type = (string) ($b['type'] ?? '');
            $text = trim((string) ($b['text'] ?? ''));
            $value = trim((string) ($b['value'] ?? ''));
            if ($type === '' || ($text === '' && $value === '')) {
                continue;
            }
            if (!isset($counts[$type])) {
                continue;
            }
            if ($text === '' || mb_strlen($text) > 25) {
                $e['buttons'] = 'Each button needs a label of up to 25 characters.';
            }
            if ($type === 'url') {
                $u = str_replace('{{1}}', 'x', $value);
                if (!filter_var($u, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($value), 'https://') || mb_strlen($value) > 2000) {
                    $e['buttons'] = 'Website buttons need a full https:// link.';
                } elseif (self::vars($value) !== [] && (self::vars($value) !== [1] || !str_ends_with($value, '{{1}}'))) {
                    $e['buttons'] = 'A link can have one variable, {{1}}, only at the very end (e.g. https://shop.com/order/{{1}}).';
                }
            } elseif ($type === 'phone') {
                $p = PhoneNormalizer::normalize($value);
                if ($p === null) {
                    $e['buttons'] = 'Call buttons need a valid phone number with country code.';
                } else {
                    $value = $p;
                }
            } else {
                $value = '';
            }
            $counts[$type]++;
            $buttons[] = ['type' => $type, 'text' => $text, 'value' => $value];
        }
        if (count($buttons) > 10 || $counts['url'] > 2 || $counts['phone'] > 1) {
            $e['buttons'] = 'Up to 10 buttons in total, with at most 2 website buttons and 1 call button.';
        }
        // Meta requires quick replies and call-to-action buttons to be grouped.
        usort($buttons, static fn ($a, $b) => ($a['type'] === 'quick_reply' ? 0 : 1) <=> ($b['type'] === 'quick_reply' ? 0 : 1));

        if ($e !== []) {
            throw new ValidationException($e);
        }
        return [
            'whatsapp_account_id' => (int) $account['id'], 'name' => $name, 'language' => $language, 'category' => $category,
            'header_type' => $headerType, 'header_text' => $headerText !== '' ? $headerText : null,
            'header_sample' => $headerSample !== '' ? $headerSample : null,
            'body' => $body, 'body_samples' => json_encode(array_values($samples), JSON_UNESCAPED_UNICODE),
            'footer' => $footer !== '' ? $footer : null, 'buttons' => json_encode($buttons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** Meta "create template" payload from a stored row. */
    public static function metaPayload(array $t): array
    {
        $components = [];
        if ($t['header_type'] === 'text') {
            $h = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => (string) $t['header_text']];
            if (self::vars((string) $t['header_text']) !== []) {
                $h['example'] = ['header_text' => [(string) $t['header_sample']]];
            }
            $components[] = $h;
        } elseif (self::needsMedia($t)) {
            $components[] = ['type' => 'HEADER', 'format' => strtoupper((string) $t['header_type']),
                'example' => ['header_handle' => [(string) $t['header_media_handle']]]];
        }
        $b = ['type' => 'BODY', 'text' => (string) $t['body']];
        $samples = self::bodySamples($t);
        if (self::vars((string) $t['body']) !== []) {
            $b['example'] = ['body_text' => [$samples]];
        }
        $components[] = $b;
        if ((string) ($t['footer'] ?? '') !== '') {
            $components[] = ['type' => 'FOOTER', 'text' => (string) $t['footer']];
        }
        $btns = [];
        foreach (self::buttons($t) as $btn) {
            $btns[] = match ($btn['type']) {
                'quick_reply' => ['type' => 'QUICK_REPLY', 'text' => $btn['text']],
                'url' => ['type' => 'URL', 'text' => $btn['text'], 'url' => $btn['value']]
                    + (self::vars($btn['value']) !== [] ? ['example' => [str_replace('{{1}}', 'sample123', $btn['value'])]] : []),
                'phone' => ['type' => 'PHONE_NUMBER', 'text' => $btn['text'], 'phone_number' => $btn['value']],
                default => null,
            };
        }
        $btns = array_values(array_filter($btns));
        if ($btns !== []) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $btns];
        }
        return ['name' => $t['name'], 'language' => $t['language'], 'category' => strtoupper((string) $t['category']),
            'allow_category_change' => true, 'components' => $components];
    }

    // ------------------------------------------------------------------ lifecycle

    public function saveDraft(array $in, ?int $userId, ?int $id = null, ?array $mediaFile = null): int
    {
        if ($id !== null) {
            $existing = $this->repo->findOrFail($id);
            if (!in_array($existing['status'], ['draft', 'rejected'], true)) {
                throw new ValidationException(['name' => 'Only drafts and rejected templates can be edited. Duplicate it to make changes.']);
            }
        }
        $data = $this->validate($in, $id);
        if (self::needsMedia($data)) {
            if ($mediaFile !== null) {
                $data['header_media_handle'] = $this->uploadSample($data, $mediaFile);
            } elseif ($id === null || empty($existing['header_media_handle']) || $existing['header_type'] !== $data['header_type']) {
                throw new ValidationException(['header_media' => 'Upload a sample ' . $data['header_type'] . ' for the header (Meta reviews it).']);
            }
        } else {
            $data['header_media_handle'] = null;
        }
        $data['updated_at'] = now();
        if ($id !== null) {
            $data['status'] = 'draft';
            $data['rejected_reason'] = null;
            $this->repo->update($id, $data);
            return $id;
        }
        return $this->repo->create($data + ['status' => 'draft', 'created_by' => $userId, 'created_at' => now()]);
    }

    /** @param array{tmp_name:string,name:string,size:int,error:int} $file */
    private function uploadSample(array $data, array $file): string
    {
        $mime = self::checkMediaFile($data['header_type'], $file, 'header_media');
        $account = (new AccountRepository($this->db, $this->tenant))->findOrFail((int) $data['whatsapp_account_id']);
        $provider = $this->templateProvider($account);
        $r = $provider->uploadTemplateSample($account, AccountRepository::credentials($account), $file['tmp_name'], $mime, (string) $file['name']);
        if (!$r['ok']) {
            throw new ValidationException(['header_media' => 'Meta upload failed: ' . ($r['error'] ?? 'unknown error')]);
        }
        return (string) $r['handle'];
    }

    /** Validates an uploaded media file; returns its detected MIME type. */
    public static function checkMediaFile(string $type, array $file, string $field): string
    {
        $limits = self::MEDIA_LIMITS[$type] ?? null;
        if ($limits === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string) ($file['tmp_name'] ?? ''))) {
            throw new ValidationException([$field => 'Upload failed. Please choose the file again.']);
        }
        if ((int) $file['size'] > $limits['max']) {
            throw new ValidationException([$field => 'File is too large (max ' . round($limits['max'] / 1048576) . ' MB for ' . $type . ').']);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!in_array($mime, $limits['mimes'], true)) {
            throw new ValidationException([$field => 'Unsupported file type (' . $mime . '). Allowed: ' . implode(', ', $limits['mimes']) . '.']);
        }
        return $mime;
    }

    private function templateProvider(array $account): TemplateProvider
    {
        $p = $this->providers?->get((string) $account['provider']);
        if (!$p instanceof TemplateProvider) {
            throw new ValidationException(['whatsapp_account_id' => 'This account\'s provider does not support approved templates.']);
        }
        return $p;
    }

    /** Submit a draft to Meta for approval. */
    public function submit(int $id): array
    {
        $t = $this->repo->findOrFail($id);
        if (!in_array($t['status'], ['draft', 'rejected'], true)) {
            throw new ValidationException(['status' => 'This template was already submitted.']);
        }
        $account = (new AccountRepository($this->db, $this->tenant))->findOrFail((int) $t['whatsapp_account_id']);
        $r = $this->templateProvider($account)->createTemplate($account, AccountRepository::credentials($account), self::metaPayload($t));
        if (!$r['ok']) {
            $this->repo->update($id, ['rejected_reason' => mb_substr((string) $r['error'], 0, 500), 'updated_at' => now()]);
            throw new ValidationException(['status' => 'Meta did not accept the template: ' . $r['error']]);
        }
        $status = in_array($r['status'] ?? '', ['approved', 'rejected', 'pending'], true) ? $r['status'] : 'pending';
        $this->repo->update($id, ['status' => $status, 'provider_template_id' => $r['id'], 'rejected_reason' => null,
            'submitted_at' => now(), 'updated_at' => now()]);
        return (array) $this->repo->find($id);
    }

    /** Import/refresh all templates from Meta for one account. Returns [created, updated, removed]. */
    public function sync(int $accountId): array
    {
        $account = (new AccountRepository($this->db, $this->tenant))->findOrFail($accountId);
        $r = $this->templateProvider($account)->listTemplates($account, AccountRepository::credentials($account));
        if (!$r['ok']) {
            throw new ValidationException(['sync' => 'Could not fetch templates from Meta: ' . $r['error']]);
        }
        $tid = $this->tenant->id();
        $created = $updated = 0;
        $seen = [];
        foreach ($r['templates'] as $mt) {
            $row = self::fromMeta($mt);
            if ($row === null) {
                continue;
            }
            $seen[] = $row['provider_template_id'];
            $existing = $this->db->first(
                'SELECT id FROM message_templates WHERE tenant_id = ? AND whatsapp_account_id = ? AND (provider_template_id = ? OR (name = ? AND language = ?))',
                [$tid, $accountId, $row['provider_template_id'], $row['name'], $row['language']]
            );
            $row += ['last_synced_at' => now(), 'updated_at' => now()];
            if ($existing !== null) {
                $this->db->update('message_templates', $row, ['id' => (int) $existing['id'], 'tenant_id' => $tid]);
                $updated++;
            } else {
                $this->repo->create($row + ['whatsapp_account_id' => $accountId, 'created_at' => now()]);
                $created++;
            }
        }
        // Templates deleted in WhatsApp Manager.
        $removed = 0;
        foreach ($this->db->all("SELECT id, provider_template_id FROM message_templates WHERE tenant_id = ? AND whatsapp_account_id = ?
            AND provider_template_id IS NOT NULL AND status <> 'deleted'", [$tid, $accountId]) as $local) {
            if (!in_array($local['provider_template_id'], $seen, true)) {
                $this->repo->update((int) $local['id'], ['status' => 'deleted', 'updated_at' => now()]);
                $removed++;
            }
        }
        $this->db->update('whatsapp_accounts', ['last_synced_at' => now()], ['id' => $accountId, 'tenant_id' => $tid]);
        return [$created, $updated, $removed];
    }

    /** Convert a Meta template object into our columns (null if unusable). */
    public static function fromMeta(array $mt): ?array
    {
        $id = (string) ($mt['id'] ?? '');
        $name = (string) ($mt['name'] ?? '');
        if ($id === '' || $name === '') {
            return null;
        }
        $row = [
            'provider_template_id' => $id, 'name' => mb_substr($name, 0, 191), 'language' => (string) ($mt['language'] ?? 'en'),
            'category' => strtolower((string) ($mt['category'] ?? 'utility')), 'status' => strtolower((string) ($mt['status'] ?? 'pending')),
            'rejected_reason' => isset($mt['rejected_reason']) && $mt['rejected_reason'] !== 'NONE' ? mb_substr((string) $mt['rejected_reason'], 0, 500) : null,
            'quality_score' => isset($mt['quality_score']['score']) ? strtolower((string) $mt['quality_score']['score']) : null,
            'header_type' => 'none', 'header_text' => null, 'header_sample' => null, 'body' => '', 'body_samples' => '[]', 'footer' => null, 'buttons' => '[]',
        ];
        $buttons = [];
        foreach ((array) ($mt['components'] ?? []) as $c) {
            switch (strtoupper((string) ($c['type'] ?? ''))) {
                case 'HEADER':
                    $fmt = strtolower((string) ($c['format'] ?? 'text'));
                    $row['header_type'] = in_array($fmt, ['text', 'image', 'video', 'document'], true) ? $fmt : 'none';
                    if ($fmt === 'text') {
                        $row['header_text'] = mb_substr((string) ($c['text'] ?? ''), 0, 60);
                        $row['header_sample'] = isset($c['example']['header_text'][0]) ? (string) $c['example']['header_text'][0] : null;
                    }
                    break;
                case 'BODY':
                    $row['body'] = (string) ($c['text'] ?? '');
                    $row['body_samples'] = json_encode(array_values((array) ($c['example']['body_text'][0] ?? [])), JSON_UNESCAPED_UNICODE);
                    break;
                case 'FOOTER':
                    $row['footer'] = isset($c['text']) ? mb_substr((string) $c['text'], 0, 60) : null;
                    break;
                case 'BUTTONS':
                    foreach ((array) ($c['buttons'] ?? []) as $b) {
                        $bt = strtoupper((string) ($b['type'] ?? ''));
                        $buttons[] = match ($bt) {
                            'QUICK_REPLY' => ['type' => 'quick_reply', 'text' => (string) ($b['text'] ?? ''), 'value' => ''],
                            'URL' => ['type' => 'url', 'text' => (string) ($b['text'] ?? ''), 'value' => (string) ($b['url'] ?? '')],
                            'PHONE_NUMBER' => ['type' => 'phone', 'text' => (string) ($b['text'] ?? ''), 'value' => (string) ($b['phone_number'] ?? '')],
                            default => ['type' => 'other', 'text' => (string) ($b['text'] ?? strtolower($bt)), 'value' => strtolower($bt)],
                        };
                    }
                    break;
            }
        }
        $row['buttons'] = json_encode($buttons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $row;
    }

    /** Apply a Meta approval/rejection webhook. */
    public function applyStatusEvent(array $account, NormalizedEvent $event): bool
    {
        $tid = $this->tenant->id();
        $t = $this->db->first(
            'SELECT * FROM message_templates WHERE tenant_id = ? AND whatsapp_account_id = ? AND (provider_template_id = ? OR (name = ? AND language = ?))',
            [$tid, $account['id'], $event->providerMessageId, (string) $event->templateName, (string) $event->templateLanguage]
        );
        if ($t === null) {
            return false;
        }
        $map = ['approved' => 'approved', 'rejected' => 'rejected', 'paused' => 'paused', 'disabled' => 'disabled',
            'pending_deletion' => 'deleted', 'deleted' => 'deleted', 'flagged' => 'approved', 'reinstated' => 'approved',
            'in_appeal' => 'pending', 'pending' => 'pending', 'limit_exceeded' => 'rejected'];
        $status = $map[(string) $event->status] ?? null;
        if ($status === null) {
            return false;
        }
        $this->repo->update((int) $t['id'], [
            'status' => $status, 'provider_template_id' => $event->providerMessageId,
            'rejected_reason' => $status === 'rejected' ? mb_substr((string) ($event->errorMessage ?? 'Rejected by Meta'), 0, 500) : null,
            'quality_score' => $event->status === 'flagged' ? 'red' : $t['quality_score'],
            'updated_at' => now(),
        ]);
        return true;
    }

    public function delete(int $id): void
    {
        $t = $this->repo->findOrFail($id);
        if ($t['provider_template_id'] !== null && $t['status'] !== 'deleted') {
            $account = (new AccountRepository($this->db, $this->tenant))->findOrFail((int) $t['whatsapp_account_id']);
            $r = $this->templateProvider($account)->deleteTemplate($account, AccountRepository::credentials($account), (string) $t['name'], (string) $t['provider_template_id']);
            if (!$r['ok']) {
                throw new ValidationException(['delete' => 'Meta could not delete it: ' . $r['error']]);
            }
        }
        $used = (int) $this->db->scalar('SELECT COUNT(*) FROM campaigns WHERE template_id = ? AND tenant_id = ?', [$id, $this->tenant->id()]);
        if ($used > 0) {
            $this->repo->update($id, ['status' => 'deleted', 'updated_at' => now()]); // keep for campaign history
        } else {
            $this->repo->delete($id);
        }
    }

    public function duplicate(int $id, ?int $userId): int
    {
        $t = $this->repo->findOrFail($id);
        $base = substr((string) $t['name'], 0, 180) . '_copy';
        $name = $base;
        for ($i = 2; $this->db->scalar('SELECT 1 FROM message_templates WHERE whatsapp_account_id = ? AND name = ? AND language = ?', [$t['whatsapp_account_id'], $name, $t['language']]) !== null; $i++) {
            $name = $base . $i;
        }
        $row = array_intersect_key($t, array_flip(['whatsapp_account_id', 'language', 'category', 'header_type', 'header_text', 'header_sample',
            'header_media_handle', 'body', 'body_samples', 'footer', 'buttons']));
        if ($row['category'] === 'authentication') {
            $row['category'] = 'utility';
        }
        return $this->repo->create($row + ['name' => $name, 'status' => 'draft', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Approved, sendable templates for an account (or all accounts). */
    public function sendable(?int $accountId = null): array
    {
        $rows = $this->db->all(
            "SELECT * FROM message_templates WHERE tenant_id = ? AND status = 'approved'" . ($accountId ? ' AND whatsapp_account_id = ' . (int) $accountId : '') . ' ORDER BY name',
            [$this->tenant->id()]
        );
        return array_values(array_filter($rows, static fn ($t) => self::unsendableReason($t) === null));
    }
}
