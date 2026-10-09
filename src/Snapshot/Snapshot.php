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
     * @param  array{profile: string, driver?: string, database: string, created_at: string, partial?: bool, tables: array<string, array{mode: string, where: ?string, bytes: int}>}  $manifest
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

    public function viewsFile(): ?string
    {
        return File::exists($file = $this->path.'/'.self::VIEWS_FILE) ? $file : null;
    }

    public function bytes(): int
    {
        return array_sum(array_map(fn (array $table): int => $table['bytes'], $this->manifest['tables']));
    }
}
