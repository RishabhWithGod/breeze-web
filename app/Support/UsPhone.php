<?php

namespace App\Support;

/**
 * A phone number the way the United States writes one.
 *
 * Everyone in this app is dialling a US number, so the format is a rule rather
 * than a preference: `(555) 123-4567`. Numbers are stored formatted, not raw,
 * because every screen and every export reads the column directly — normalising
 * once on the way in is one place to be right, rather than thirty places to
 * remember.
 *
 * The validity check is the North American Numbering Plan's own, not a length
 * count: an area code and an exchange code both have to start 2-9. That rejects
 * the placeholders people actually type — 0000000000, 1234567890 — which a
 * ten-digit rule would happily accept.
 */
final class UsPhone
{
    /** Ten digits, or null when what was given cannot be one. */
    public static function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        // A leading 1 is the country code, which is not part of the number.
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    public static function valid(?string $value): bool
    {
        $digits = self::digits($value);

        // Area code and exchange code both begin 2-9. N11 area codes (211, 911
        // and the rest) are service numbers, never a person's line.
        return $digits !== null
            && preg_match('/^[2-9][0-8][0-9][2-9][0-9]{6}$/', $digits) === 1;
    }

    /** "9837645221" → "(983) 764-5221". Anything that is not a US number is returned untouched. */
    public static function format(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $digits = self::digits($value);

        if ($digits === null) {
            // Kept as typed rather than mangled: a number this class does not
            // recognise is somebody's data, and losing it is worse than showing
            // it in the wrong shape.
            return $value;
        }

        return sprintf('(%s) %s-%s',
            substr($digits, 0, 3),
            substr($digits, 3, 3),
            substr($digits, 6),
        );
    }
}
