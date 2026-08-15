<?php

namespace App\Helpers;

class PhoneHelper
{
    /**
     * Normalize Tanzanian phone to 0XXXXXXXXX format.
     * Handles: +255XXXXXXXXX, 255XXXXXXXXX, 0XXXXXXXXX, XXXXXXXX (9 digits).
     * Note: Validation (10-13 digits) should be done at the validation layer.
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $phone = preg_replace('/[\s\-\(\)\.]/', '', trim($phone));
        
        if (str_starts_with($phone, '+255')) {
            return '0' . substr($phone, 4);
        }
        if (preg_match('/^255(\d{9})$/', $phone, $m)) {
            return '0' . $m[1];
        }
        if (preg_match('/^(\d{9})$/', $phone)) {
            return '0' . $phone;
        }
        return $phone;
    }
}
