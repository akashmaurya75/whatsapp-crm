<?php

declare(strict_types=1);

namespace App\Domain\Contacts;

/**
 * Normalizes phone numbers to E.164 (+<country><number>, 8–15 digits).
 * National numbers use DEFAULT_COUNTRY_CODE (e.g. 91 for India).
 */
final class PhoneNormalizer
{
    public static function normalize(string $input, ?string $defaultCountryCode = null): ?string
    {
        $defaultCountryCode ??= (string) env('DEFAULT_COUNTRY_CODE', '');
        $raw = trim($input);
        if ($raw === '') {
            return null;
        }
        // WhatsApp ids like "919876543210@c.us" / "@s.whatsapp.net"
        $raw = (string) preg_replace('/@.*$/', '', $raw);
        $hasPlus = str_starts_with($raw, '+');
        $digits = (string) preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }
        if (!$hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hasPlus = true;
        }
        if (!$hasPlus) {
            $cc = preg_replace('/\D+/', '', $defaultCountryCode) ?? '';
            if (str_starts_with($digits, '0') && $cc !== '') {
                $digits = $cc . ltrim($digits, '0');
            } elseif ($cc !== '' && strlen($digits) <= 10) {
                $digits = $cc . $digits;
            }
            // Otherwise assume it already includes the country code (WhatsApp wa_id format).
        }
        if (!preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            return null;
        }
        return '+' . $digits;
    }

    /** WhatsApp Cloud API expects digits only (no "+"). */
    public static function toWaId(string $e164): string
    {
        return ltrim($e164, '+');
    }
}
