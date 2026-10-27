<?php

namespace App\Support;

final class PhoneNumber
{
    public static function normalize(string $phone): string
    {
        $cleaned = trim($phone);
        $hasPlus = str_starts_with($cleaned, '+');
        $digitsOnly = preg_replace('/[^\d]/', '', $cleaned) ?? '';

        return $hasPlus ? '+'.$digitsOnly : $digitsOnly;
    }

    public static function isValid(string $phone): bool
    {
        $normalized = self::normalize($phone);

        return $normalized !== ''
            && preg_match('/^\+?\d{3,15}$/', $normalized) === 1;
    }
}
