<?php

namespace Overthink\DbSnapshot\Profile;

enum AnonymizeStrategy: string
{
    case Email = 'email';
    case Hash = 'hash';
    case Template = 'template';
    case Fixed = 'fixed';
    case Null = 'null';
    case Empty = 'empty';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email — user_<hash>@example.test',
            self::Hash => 'Hash — 12 hex characters',
            self::Template => 'Template — text with {hash} or {id}',
            self::Fixed => 'Fixed — the same value for every row',
            self::Null => 'NULL',
            self::Empty => "Empty string ''",
        };
    }

    /**
     * Whether the strategy builds text from the original value, and so only
     * fits text columns.
     */
    public function needsTextColumn(): bool
    {
        return in_array($this, [self::Email, self::Hash, self::Template, self::Empty], true);
    }
}
