<?php

namespace App\Enums;

enum SocialProvider: string
{
    case Google = 'google';
    case Apple = 'apple';

    /**
     * The users-table column that stores this provider's stable user id.
     */
    public function identifierColumn(): string
    {
        return match ($this) {
            self::Google => 'google_id',
            self::Apple => 'apple_id',
        };
    }
}
