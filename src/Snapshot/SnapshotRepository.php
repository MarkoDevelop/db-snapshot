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
     */
    public function all(): array
    {
        if (! File::isDirectory($this->path)) {
            return [];
        }

        $snapshots = [];

        foreach (File::directories($this->path) as $directory) {
            if (File::exists($directory.'/manifest.json') && ! ($snapshot = Snapshot::load($directory))->isPartial()) {
                $snapshots[] = $snapshot;
            }
        }

        usort($snapshots, fn (Snapshot $a, Snapshot $b): int => $b->createdAt() <=> $a->createdAt());

        return $snapshots;
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
}
