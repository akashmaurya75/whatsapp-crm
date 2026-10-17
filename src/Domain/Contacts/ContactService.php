<?php

declare(strict_types=1);

namespace App\Domain\Contacts;

use App\Core\Database;
use App\Core\Validator;
use App\Domain\ValidationException;
use App\Tenancy\TenantContext;

final class ContactService
{
    public const LEAD_STATUSES = ['new', 'contacted', 'qualified', 'customer', 'lost'];
    public const CONSENT = ['unknown', 'granted', 'revoked'];

    public readonly ContactRepository $repo;

    public function __construct(private Database $db, private TenantContext $tenant)
    {
        $this->repo = new ContactRepository($db, $tenant);
    }

    private function rules(bool $creating): array
    {
        return [
            'name' => 'nullable|string|max:160',
            'phone' => ($creating ? 'required' : 'nullable') . '|string|max:32',
            'email' => 'nullable|email|max:191',
            'company' => 'nullable|string|max:160',
            'city' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:60',
            'lead_status' => 'nullable|in:' . implode(',', self::LEAD_STATUSES),
            'consent_status' => 'nullable|in:' . implode(',', self::CONSENT),
            'owner_user_id' => 'nullable|int',
            'next_follow_up_at' => 'nullable|string|max:20',
        ];
    }

    /** @return array<string,mixed> validated + normalized columns */
    private function prepare(array $input, bool $creating, ?int $ignoreId = null): array
    {
        $v = Validator::make($input, $this->rules($creating));
        $data = $v->validated();
        $errors = $v->errors();
        $out = [];
        if (array_key_exists('phone', $input) && !isset($errors['phone']) && $data['phone'] !== null) {
            $e164 = PhoneNormalizer::normalize((string) $data['phone']);
            if ($e164 === null) {
                $errors['phone'] = 'Enter a valid phone number in international format, e.g. +919876543210.';
            } else {
                $dup = $this->repo->findByPhone($e164);
                if ($dup !== null && (int) $dup['id'] !== $ignoreId) {
                    $errors['phone'] = 'A contact with this phone number already exists.';
                }
                $out['phone_e164'] = $e164;
            }
        }
        if (!empty($data['owner_user_id'])) {
            $ok = $this->db->scalar('SELECT 1 FROM users WHERE id = ? AND tenant_id = ?', [$data['owner_user_id'], $this->tenant->id()]);
            if ($ok === null) {
                $errors['owner_user_id'] = 'Unknown user.';
            }
        }
        if (!empty($data['next_follow_up_at']) && strtotime((string) $data['next_follow_up_at']) === false) {
            $errors['next_follow_up_at'] = 'Invalid date.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        foreach (['name', 'email', 'company', 'city', 'address', 'source', 'lead_status', 'owner_user_id'] as $f) {
            if (array_key_exists($f, $input)) {
                $out[$f] = $data[$f] === '' ? null : $data[$f];
            }
        }
        if (isset($out['email'])) {
            $out['email'] = strtolower((string) $out['email']);
        }
        if (array_key_exists('next_follow_up_at', $input)) {
            $out['next_follow_up_at'] = !empty($data['next_follow_up_at']) ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $data['next_follow_up_at'])) : null;
        }
        if (!empty($data['consent_status'])) {
            $out['consent_status'] = $data['consent_status'];
            $out['consent_at'] = now();
            $out['opted_out_at'] = $data['consent_status'] === 'revoked' ? now() : null;
        }
        if (($out['lead_status'] ?? '') === null) {
            unset($out['lead_status']);
        }
        return $out;
    }

    public function create(array $input): int
    {
        $data = $this->prepare($input, true);
        $data += ['lead_status' => 'new', 'consent_status' => 'unknown'];
        $data['created_at'] = $data['updated_at'] = now();
        try {
            return $this->repo->create($data);
        } catch (\PDOException $e) {
            if ($this->db->isUniqueViolation($e)) {
                throw new ValidationException(['phone' => 'A contact with this phone number already exists.']);
            }
            throw $e;
        }
    }

    public function update(int $id, array $input): void
    {
        $current = $this->repo->findOrFail($id);
        if (($input['consent_status'] ?? null) === $current['consent_status']) {
            unset($input['consent_status']); // keep the original consent timestamp
        }
        $data = $this->prepare($input, false, $id);
        if ($data === []) {
            return;
        }
        $data['updated_at'] = now();
        $this->repo->update($id, $data);
    }

    public function archive(int $id): void
    {
        $this->repo->findOrFail($id);
        $this->repo->update($id, ['archived_at' => now(), 'updated_at' => now()]);
    }

    public function restore(int $id): void
    {
        $this->repo->findOrFail($id);
        $this->repo->update($id, ['archived_at' => null, 'updated_at' => now()]);
    }

    /** Find a contact by phone, creating it (source = whatsapp) if new. Used for inbound messages. */
    public function findOrCreateByPhone(string $e164, ?string $name): array
    {
        $existing = $this->repo->findByPhone($e164);
        if ($existing !== null) {
            if ($existing['name'] === null && $name !== null && $name !== '') {
                $this->repo->update((int) $existing['id'], ['name' => mb_substr($name, 0, 160), 'updated_at' => now()]);
                $existing['name'] = $name;
            }
            return $existing;
        }
        try {
            $id = $this->repo->create([
                'name' => $name !== null && $name !== '' ? mb_substr($name, 0, 160) : null,
                'phone_e164' => $e164, 'source' => 'whatsapp', 'lead_status' => 'new',
                'consent_status' => 'unknown', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\PDOException $e) {
            if ($this->db->isUniqueViolation($e) && ($row = $this->repo->findByPhone($e164)) !== null) {
                return $row; // concurrent webhook created it
            }
            throw $e;
        }
        return (array) $this->repo->find($id);
    }

    /** Stream authorized contacts as CSV (formula-injection safe). */
    public function exportCsv(array $filters): string
    {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['id', 'name', 'phone', 'email', 'company', 'city', 'source', 'lead_status', 'consent_status', 'opted_out_at', 'created_at'], ',', '"', '\\');
        $page = 1;
        do {
            $result = $this->repo->search($filters, $page, 100);
            foreach ($result['data'] as $c) {
                fputcsv($fh, array_map([self::class, 'csvSafe'], [
                    $c['id'], $c['name'], $c['phone_e164'], $c['email'], $c['company'], $c['city'],
                    $c['source'], $c['lead_status'], $c['consent_status'], $c['opted_out_at'], $c['created_at'],
                ]), ',', '"', '\\');
            }
            $page++;
        } while (count($result['data']) === 100);
        rewind($fh);
        return (string) stream_get_contents($fh);
    }

    public static function csvSafe(mixed $v): string
    {
        $s = (string) $v;
        return ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !preg_match('/^\+\d+$/', $s)) ? "'" . $s : $s;
    }
}
