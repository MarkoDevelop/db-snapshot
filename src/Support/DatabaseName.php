<?php

namespace Overthink\DbSnapshot\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Overthink\DbSnapshot\Snapshot\Snapshot;

/**
 * Database names from templates like "{source}_{date}", for keeping one local
 * database per snapshot.
 */
final class DatabaseName
{
    /**
     * Placeholders: {source} (remote database), {profile}, {date} (Y_m_d of the
     * pull), {time} (His of the pull) and {database} (the local database).
     */
    public static function resolve(string $template, Snapshot $snapshot, string $localDatabase): string
    {
        return self::resolveFor($template, $snapshot->manifest['database'], $snapshot->profile(), $snapshot->createdAt(), $localDatabase);
    }

    /**
     * The same, before the snapshot exists (e.g. to ask where to restore before pulling).
     */
    public static function resolveFor(string $template, string $source, string $profile, CarbonImmutable $pulledAt, string $localDatabase): string
    {
        $name = strtr($template, [
            '{source}' => $source,
            '{profile}' => $profile,
            '{date}' => $pulledAt->format('Y_m_d'),
            '{time}' => $pulledAt->format('His'),
            '{database}' => $localDatabase,
        ]);

        if (! preg_match('/^[A-Za-z0-9_$-]{1,63}$/', $name)) {
            throw new InvalidArgumentException("[{$name}] is not a valid database name (letters, digits, _, - and \$, at most 63 characters).");
        }

        return $name;
    }
}
