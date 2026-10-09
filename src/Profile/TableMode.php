<?php

namespace Overthink\DbSnapshot\Profile;

enum TableMode: string
{
    case Full = 'full';
    case Schema = 'schema';
    case Recent = 'recent';
    case Where = 'where';
    case Skip = 'skip';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full — all rows',
            self::Schema => 'Schema only — no rows',
            self::Recent => 'Recent — rows newer than a date',
            self::Where => 'Custom WHERE clause',
            self::Skip => 'Skip — not even the table',
        };
    }
}
