<?php

namespace Database\Seeders\Support;

/**
 * firstname.lastname@tradewatch.go.ke, per the user's explicit convention
 * for every newly-generated named user in the demo dataset.
 */
final class EmailSlug
{
    public static function for(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $first = self::normalize($parts[0] ?? 'user');
        $last = self::normalize(end($parts) ?: 'user');

        return "{$first}.{$last}@tradewatch.go.ke";
    }

    private static function normalize(string $part): string
    {
        return strtolower(str_replace(["'", '.'], '', $part));
    }
}
