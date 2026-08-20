<?php

namespace App\Support;

final class MailRecipient
{
    /** @return list<string> */
    public static function to(string $email): array
    {
        return [self::address($email)];
    }

    public static function address(string $email): string
    {
        if (app()->environment('production')) {
            return $email;
        }

        $redirect = trim((string) config('mail.redirect_to', ''));

        return $redirect !== '' ? $redirect : $email;
    }
}
