<?php

namespace Overthink\DbSnapshot\Commands;

use Closure;
use Overthink\DbSnapshot\Support\EnvFile;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * The local database that restores write into, and the guard that keeps
 * them away from production.
 */
trait TargetsLocalDatabase
{
    /**
     * The connection config of SNAPSHOT_CONNECTION, or of the app's default connection.
     *
     * @return array<string, mixed>
     */
    protected function localConnection(): array
    {
        return (array) config('database.connections.'.(config('db-snapshot.connection') ?? config('database.default')));
    }

    /**
     * The app's database, a new one named $suggested, or a typed name.
     *
     * @param  Closure(string): string  $resolve  turns a typed name (with placeholders) into the final one
     */
    protected function askRestoreDatabase(string $appDatabase, string $suggested, Closure $resolve): string
    {
        $choice = select(
            label: 'Restore into which database?',
            options: array_filter([
                'app' => "{$appDatabase} (the app's database)",
                'new' => $suggested !== $appDatabase ? "{$suggested} (a database for this snapshot)" : null,
                'other' => 'Another name…',
            ]),
            default: 'app',
        );

        return match ($choice) {
            'app' => $appDatabase,
            'new' => $suggested,
            default => $resolve(text(
                label: 'Database name',
                default: $suggested,
                required: true,
                hint: 'Placeholders: {source}, {profile}, {date}, {time}, {database}',
            )),
        };
    }

    protected function askToSwitchApp(string $database, bool $beforeRestoring = false): bool
    {
        $when = $beforeRestoring ? ' once it is restored' : '';

        return confirm("Point the app at {$database}{$when}? (sets DB_DATABASE in {$this->laravel->environmentFilePath()})", default: false);
    }

    protected function switchApp(string $database): void
    {
        (new EnvFile($this->laravel->environmentFilePath()))->set(['DB_DATABASE' => $database]);
        info("DB_DATABASE is now {$database}. Run php artisan config:clear if the config is cached.");
    }

    protected function refusesProduction(): bool
    {
        if (! $this->laravel->isProduction()) {
            return false;
        }

        error("{$this->getName()} never runs in production.");

        return true;
    }
}
