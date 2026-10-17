<?php

declare(strict_types=1);

namespace App\Domain\Contacts;

use App\Core\Database;
use App\Domain\ValidationException;
use App\Tenancy\TenantContext;

/**
 * CSV contact import. Columns are matched by header name (case-insensitive):
 *   phone (required) | name / first name + last name | email | company | city | address | source | tags | consent | lead status
 * Phones are normalised to E.164; existing contacts (same number) are updated or skipped.
 */
final class ContactImporter
{
    public const MAX_ROWS = 20000;
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const ALIASES = [
        'phone' => ['phone', 'mobile', 'whatsapp', 'whatsapp number', 'phone number', 'mobile number', 'number', 'contact number', 'msisdn'],
        'name' => ['name', 'full name', 'contact name', 'customer name'],
        'first_name' => ['first name', 'firstname'],
        'last_name' => ['last name', 'lastname', 'surname'],
        'email' => ['email', 'email address', 'e-mail'],
        'company' => ['company', 'company name', 'organisation', 'organization', 'business'],
        'city' => ['city', 'town'],
        'address' => ['address'],
        'source' => ['source', 'lead source'],
        'tags' => ['tags', 'tag', 'labels', 'groups'],
        'consent' => ['consent', 'opt in', 'opt-in', 'optin', 'whatsapp consent', 'marketing consent'],
        'lead_status' => ['lead status', 'status', 'stage'],
    ];

    public function __construct(private Database $db, private TenantContext $tenant)
    {
    }

    /** Map header cells to fields. @return array<string,int> field => column index */
    public static function mapHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $i => $h) {
            $h = strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));
            $h = (string) preg_replace('/[_\s]+/', ' ', $h);
            foreach (self::ALIASES as $field => $aliases) {
                if (!isset($map[$field]) && in_array($h, $aliases, true)) {
                    $map[$field] = (int) $i;
                    break;
                }
            }
        }
        return $map;
    }

    public static function consentValue(string $v): ?string
    {
        $v = strtolower(trim($v));
        return match (true) {
            in_array($v, ['yes', 'y', 'true', '1', 'granted', 'opted in', 'opt in', 'subscribed'], true) => 'granted',
            in_array($v, ['no', 'n', 'false', '0', 'revoked', 'opted out', 'opt out', 'unsubscribed'], true) => 'revoked',
            default => null,
        };
    }

    /**
     * @param array{tags?:string,consent_all?:bool,update_existing?:bool,source?:string} $opts
     * @return array{created:int,updated:int,skipped:int,errors:list<array{row:int,reason:string}>,tag_names:list<string>}
     */
    public function import(string $path, array $opts): array
    {
        if (!is_file($path) || filesize($path) === 0) {
            throw new ValidationException(['file' => 'Choose a CSV file to import.']);
        }
        if (filesize($path) > self::MAX_BYTES) {
            throw new ValidationException(['file' => 'File is larger than 5 MB. Split it into smaller files.']);
        }
        $fh = fopen($path, 'r');
        $first = (string) fgets($fh);
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        rewind($fh);
        $headers = fgetcsv($fh, 0, $delim, '"', '\\') ?: [];
        $cols = self::mapHeaders($headers);
        if (!isset($cols['phone'])) {
            fclose($fh);
            throw new ValidationException(['file' => 'No phone column found. Name the column "phone" (or mobile / whatsapp). Found: ' . implode(', ', array_slice(array_map('strval', $headers), 0, 8))]);
        }
        $extraTags = array_values(array_filter(array_map('trim', explode(',', (string) ($opts['tags'] ?? '')))));
        $consentAll = !empty($opts['consent_all']);
        $update = !empty($opts['update_existing']);
        $source = mb_substr(trim((string) ($opts['source'] ?? '')), 0, 60) ?: 'import';
        $tid = $this->tenant->id();
        $contacts = new ContactService($this->db, $this->tenant);
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'tag_names' => $extraTags];
        $rowNo = 1;
        $seen = [];
        $this->db->transaction(function () use ($fh, $delim, $cols, $extraTags, $consentAll, $update, $source, $tid, $contacts, &$result, &$rowNo, &$seen): void {
            while (($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
                $rowNo++;
                if ($row === [null] || implode('', array_map('strval', $row)) === '') {
                    continue;
                }
                if ($rowNo - 1 > self::MAX_ROWS) {
                    $result['errors'][] = ['row' => $rowNo, 'reason' => 'Stopped: more than ' . self::MAX_ROWS . ' rows. Import the rest in another file.'];
                    break;
                }
                $get = static fn (string $f) => isset($cols[$f]) ? trim((string) ($row[$cols[$f]] ?? '')) : '';
                $phone = PhoneNormalizer::normalize($get('phone'));
                if ($phone === null) {
                    $result['skipped']++;
                    $result['errors'][] = ['row' => $rowNo, 'reason' => 'Invalid phone "' . mb_substr($get('phone'), 0, 30) . '"'];
                    continue;
                }
                if (isset($seen[$phone])) {
                    $result['skipped']++;
                    $result['errors'][] = ['row' => $rowNo, 'reason' => "Duplicate of row {$seen[$phone]} in this file"];
                    continue;
                }
                $seen[$phone] = $rowNo;
                $name = $get('name') !== '' ? $get('name') : trim($get('first_name') . ' ' . $get('last_name'));
                $email = strtolower($get('email'));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $result['errors'][] = ['row' => $rowNo, 'reason' => "Invalid email ignored ($email)"];
                    $email = '';
                }
                $consent = $consentAll ? 'granted' : self::consentValue($get('consent'));
                $lead = strtolower($get('lead_status'));
                $data = array_filter([
                    'name' => mb_substr($name, 0, 160), 'email' => mb_substr($email, 0, 191), 'company' => mb_substr($get('company'), 0, 160),
                    'city' => mb_substr($get('city'), 0, 120), 'address' => mb_substr($get('address'), 0, 255),
                    'lead_status' => in_array($lead, ContactService::LEAD_STATUSES, true) ? $lead : '',
                ], static fn ($v) => $v !== '');
                if ($consent !== null) {
                    $data['consent_status'] = $consent;
                    $data['consent_at'] = now();
                    $data['opted_out_at'] = $consent === 'revoked' ? now() : null;
                }
                $existing = $contacts->repo->findByPhone($phone);
                if ($existing !== null) {
                    if (!$update) {
                        $result['skipped']++;
                        $id = (int) $existing['id'];
                    } else {
                        // Never silently re-subscribe someone who opted out.
                        if ($existing['opted_out_at'] !== null && ($data['consent_status'] ?? '') === 'granted') {
                            unset($data['consent_status'], $data['consent_at'], $data['opted_out_at']);
                            $result['errors'][] = ['row' => $rowNo, 'reason' => 'Kept opt-out: this contact previously unsubscribed'];
                        }
                        $data['updated_at'] = now();
                        $contacts->repo->update((int) $existing['id'], $data);
                        $result['updated']++;
                        $id = (int) $existing['id'];
                    }
                } else {
                    $id = $contacts->repo->create($data + ['phone_e164' => $phone, 'source' => $get('source') !== '' ? mb_substr($get('source'), 0, 60) : $source,
                        'lead_status' => $data['lead_status'] ?? 'new', 'consent_status' => $data['consent_status'] ?? 'unknown',
                        'created_at' => now(), 'updated_at' => now()]);
                    $result['created']++;
                }
                $rowTags = array_filter(array_map('trim', preg_split('/[;|,]/', $get('tags')) ?: []));
                $tags = array_merge($rowTags, $extraTags);
                if ($tags !== [] && ($existing === null || $update)) {
                    $contacts->repo->syncTags($id, array_merge($contacts->repo->tags($id), $tags));
                }
            }
        });
        fclose($fh);
        $result['errors'] = array_slice($result['errors'], 0, 200);
        return $result;
    }
}
