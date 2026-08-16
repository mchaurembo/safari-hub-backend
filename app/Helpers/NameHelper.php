<?php

namespace App\Helpers;

class NameHelper
{
    /**
     * Format a person name as Title Case (e.g. "SHUKURU ramadhani" → "Shukuru Ramadhani").
     */
    public static function personName(?string $name): string
    {
        if ($name === null || trim($name) === '') {
            return '';
        }

        $parts = preg_split('/\s+/u', trim($name)) ?: [];

        return collect($parts)
            ->filter(fn ($part) => $part !== '')
            ->map(fn (string $part) => mb_convert_case(mb_strtolower($part, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'))
            ->implode(' ');
    }
}
