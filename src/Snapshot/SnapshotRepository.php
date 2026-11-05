<?php

namespace Overthink\DbSnapshot\Snapshot;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class SnapshotRepository
{
    public function __construct(public readonly string $path) {}

    /**
     * Complete, non-partial snapshots (those with a manifest), newest first.
     *
     * @return list<Snapshot>
     *
     * @phpstan-impure
     */
    public function all(): array
    {
        return array_values(array_filter($this->loaded(), fn (Snapshot $snapshot): bool => ! $snapshot->isPartial()));
    }

    /**
     * Saved add-ons, newest first.
     *
     * @return list<Snapshot>
     *
     * @phpstan-impure
     */
    public function addons(): array
    {
        return array_values(array_filter($this->loaded(), fn (Snapshot $snapshot): bool => $snapshot->isAddon()));
    }

    /**
     * Find a snapshot by directory name, or "latest" (optionally of a profile).
     */
    public function find(string $name = 'latest', ?string $profile = null): Snapshot
    {
        foreach ($this->all() as $snapshot) {
            if ($name === 'latest' ? ($profile === null || $snapshot->profile() === $profile) : $snapshot->name() === $name) {
                return $snapshot;
            }
        }

        throw new RuntimeException("No snapshot [{$name}] in {$this->path}. Run snapshot:pull first.");
    }

    /**
     * Find an add-on by its name (the newest one) or its directory name.
     */
    public function findAddon(string $name): Snapshot
    {
        foreach ($this->addons() as $addon) {
            if ($addon->profile() === $name || $addon->name() === $name) {
                return $addon;
            }
        }

        throw new RuntimeException("No add-on [{$name}] in {$this->path}. Save one with snapshot:refresh-table --save={$name}.");
    }

    /**
     * Fold older add-ons with the same name into $kept, the one just pulled:
     * their tables that $kept doesn't have are moved over (keeping when they
     * were pulled), then the older add-ons are deleted.
     *
     * A table only enters the manifest once its file is in $kept, and files
     * an interrupted merge moved without listing them are picked up too, so
     * running the merge again after a crash loses nothing.
     */
    public function mergeOlderAddons(Snapshot $kept): Snapshot
    {
        $manifest = $kept->manifest;
        $older = [];

        foreach ($this->addons() as $addon) {
            if ($addon->profile() !== $kept->profile() || $addon->path === $kept->path) {
                continue;
            }

            if ($addon->driver() !== $kept->driver()) {
                throw new RuntimeException("Add-on {$addon->name()} was pulled with the {$addon->driver()} driver and can't be merged into a {$kept->driver()} one.");
            }

            $older[] = $addon;
            $tables = array_unique([...array_keys($addon->manifest['tables']), ...array_keys($addon->tableFiles())]);

            foreach ($tables as $table) {
                if (isset($manifest['tables'][$table])) {
                    continue;
                }

                foreach ([Snapshot::TABLES_DIRECTORY, Snapshot::POST_DATA_DIRECTORY] as $directory) {
                    if (File::exists($file = "{$addon->path}/{$directory}/{$table}.sql.gz")) {
                        File::ensureDirectoryExists("{$kept->path}/{$directory}");
                        File::move($file, "{$kept->path}/{$directory}/{$table}.sql.gz");
                    }
                }

                $keptFile = $kept->path.'/'.Snapshot::TABLES_DIRECTORY."/{$table}.sql.gz";

                if (! File::exists($keptFile)) {
                    continue;
                }

                $manifest['tables'][$table] = ($addon->manifest['tables'][$table] ?? ['mode' => 'unknown', 'where' => null, 'bytes' => File::size($keptFile)])
                    + ['pulled_at' => $addon->manifest['created_at']];
            }
        }

        ksort($manifest['tables']);
        File::put($kept->path.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        foreach ($older as $addon) {
            File::deleteDirectory($addon->path);
        }

        return Snapshot::load($kept->path);
    }

    /**
     * Every snapshot with a manifest, newest first.
     *
     * @return list<Snapshot>
     *
     * @phpstan-impure
     */
    private function loaded(): array
    {
        if (! File::isDirectory($this->path)) {
            return [];
        }

        $snapshots = [];

        foreach (File::directories($this->path) as $directory) {
            if (File::exists($directory.'/manifest.json')) {
                $snapshots[] = Snapshot::load($directory);
            }
        }

        usort($snapshots, fn (Snapshot $a, Snapshot $b): int => $b->createdAt() <=> $a->createdAt());

        return $snapshots;
    }
}
