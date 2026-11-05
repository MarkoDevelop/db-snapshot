<?php

namespace Overthink\DbSnapshot\Snapshot;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * A pulled snapshot: a directory of gzipped per-table dumps plus manifest.json.
 */
final class Snapshot
{
    public const TABLES_DIRECTORY = 'tables';

    public const ROUTINES_FILE = '_routines.sql.gz';

    public const VIEWS_FILE = '_views.sql.gz';

    public const POST_DATA_FILE = '_post-data.sql.gz';

    /**
     * Add-ons keep indexes and constraints per table, so merging moves them with their table.
     */
    public const POST_DATA_DIRECTORY = 'post-data';

    /**
     * @param  array{profile: string, driver?: string, database: string, created_at: string, partial?: bool, addon?: bool, tables: array<string, array{mode: string, where: ?string, bytes: int, pulled_at?: string}>}  $manifest
     */
    public function __construct(
        public readonly string $path,
        public readonly array $manifest,
    ) {}

    public static function load(string $path): self
    {
        $manifestPath = $path.'/manifest.json';

        if (! File::exists($manifestPath)) {
            throw new RuntimeException("{$path} has no manifest.json.");
        }

        return new self($path, json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Partial snapshots hold only some tables; they are never restored as a whole.
     */
    public function isPartial(): bool
    {
        return (bool) ($this->manifest['partial'] ?? false);
    }

    /**
     * Add-ons are partial snapshots kept on purpose: a few tables, often with
     * other rules than the base snapshot's, applied on top of a restore.
     */
    public function isAddon(): bool
    {
        return (bool) ($this->manifest['addon'] ?? false);
    }

    /**
     * Names of the tables with data or structure in the snapshot.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return array_keys($this->manifest['tables']);
    }

    /**
     * The driver that pulled the snapshot (snapshots without one are from mysql).
     */
    public function driver(): string
    {
        return $this->manifest['driver'] ?? 'mysql';
    }

    public function name(): string
    {
        return basename($this->path);
    }

    public function profile(): string
    {
        return $this->manifest['profile'];
    }

    public function createdAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->manifest['created_at']);
    }

    /**
     * When the oldest table was pulled: an add-on saved to several times
     * holds tables from each pull.
     */
    public function oldestTableAt(): CarbonImmutable
    {
        $oldest = $this->createdAt();

        foreach ($this->manifest['tables'] as $table) {
            if (isset($table['pulled_at']) && ($pulledAt = CarbonImmutable::parse($table['pulled_at']))->lessThan($oldest)) {
                $oldest = $pulledAt;
            }
        }

        return $oldest;
    }

    /**
     * Table dump files, largest first.
     *
     * @return array<string, string> table name => file path
     */
    public function tableFiles(): array
    {
        $files = [];

        foreach (File::glob($this->path.'/'.self::TABLES_DIRECTORY.'/*.sql.gz') as $file) {
            $files[basename($file, '.sql.gz')] = $file;
        }

        uasort($files, fn (string $a, string $b): int => filesize($b) <=> filesize($a));

        return $files;
    }

    public function routinesFile(): ?string
    {
        return File::exists($file = $this->path.'/'.self::ROUTINES_FILE) ? $file : null;
    }

    public function postDataFile(): ?string
    {
        return File::exists($file = $this->path.'/'.self::POST_DATA_FILE) ? $file : null;
    }

    /**
     * Index, constraint and trigger files: the snapshot-wide one and, for
     * add-ons, one per table (only those of $only when given).
     *
     * @param  list<string>|null  $only
     * @return list<string>
     */
    public function postDataFiles(?array $only = null): array
    {
        $files = $this->postDataFile() !== null ? [$this->postDataFile()] : [];

        foreach (File::glob($this->path.'/'.self::POST_DATA_DIRECTORY.'/*.sql.gz') as $file) {
            if ($only === null || in_array(basename($file, '.sql.gz'), $only, true)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    public function viewsFile(): ?string
    {
        return File::exists($file = $this->path.'/'.self::VIEWS_FILE) ? $file : null;
    }

    public function bytes(): int
    {
        return array_sum(array_map(fn (array $table): int => $table['bytes'], $this->manifest['tables']));
    }
}
