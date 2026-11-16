<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Normalise an Indonesian mobile number to international digits without "+" (62812…).
     * Returns null for empty or implausible input.
     */
    public static function normalize($value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/[^0-9+\s().-]/', $raw)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }

        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }
        if (strpos($digits, '0') === 0) {
            $digits = '62' . substr($digits, 1);
        } elseif (strpos($digits, '8') === 0) {
            $digits = '62' . $digits;
        }

        return strlen($digits) >= 9 && strlen($digits) <= 15 ? $digits : null;
    }
}
