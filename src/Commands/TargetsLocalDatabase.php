<?php

namespace Overthink\DbSnapshot\Commands;

use function Laravel\Prompts\error;

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

    protected function refusesProduction(): bool
    {
        if (! $this->laravel->isProduction()) {
            return false;
        }

        error("{$this->getName()} never runs in production.");

        return true;
    }
}
