<?php

namespace Overthink\DbSnapshot\Support;

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
        $name = strtr($template, [
            '{source}' => $snapshot->manifest['database'],
            '{profile}' => $snapshot->profile(),
            '{date}' => $snapshot->createdAt()->format('Y_m_d'),
            '{time}' => $snapshot->createdAt()->format('His'),
            '{database}' => $localDatabase,
        ]);

        if (! preg_match('/^[A-Za-z0-9_$-]{1,63}$/', $name)) {
            throw new InvalidArgumentException("[{$name}] is not a valid database name (letters, digits, _, - and \$, at most 63 characters).");
        }

        return $name;
    }
}
