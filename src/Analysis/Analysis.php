<?php

namespace Overthink\DbSnapshot\Analysis;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Sizes and date columns of the source database's tables, cached as JSON.
 */
final class Analysis
{
    /**
     * @param  array<string, TableInfo>  $tables  keyed by table name, largest first
     */
    public function __construct(
        public readonly string $database,
        public readonly CarbonImmutable $analyzedAt,
        public readonly array $tables,
    ) {}

    public function table(string $name): ?TableInfo
    {
        return $this->tables[$name] ?? null;
    }

    public function isOlderThanDays(int $days): bool
    {
        return $this->analyzedAt->lt(CarbonImmutable::now()->subDays($days));
    }

    public function save(string $path): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'database' => $this->database,
            'analyzed_at' => $this->analyzedAt->toIso8601String(),
            'tables' => array_values(array_map(fn (TableInfo $table): array => $table->toArray(), $this->tables)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    public static function load(string $path): ?self
    {
        if (! File::exists($path)) {
            return null;
        }

        $data = json_decode(File::get($path), true);

        if (! is_array($data) || ! isset($data['database'], $data['analyzed_at'], $data['tables'])) {
            throw new RuntimeException("{$path} is not a valid analysis file.");
        }

        $tables = [];

        foreach ($data['tables'] as $table) {
            $tables[$table['name']] = TableInfo::fromArray($table);
        }

        return new self($data['database'], CarbonImmutable::parse($data['analyzed_at']), $tables);
    }
}
