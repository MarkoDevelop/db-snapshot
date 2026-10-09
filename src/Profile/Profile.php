<?php

namespace Overthink\DbSnapshot\Profile;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Overthink\DbSnapshot\Analysis\Analysis;
use RuntimeException;

/**
 * Which tables a snapshot contains and how much of each, stored as JSON.
 */
final class Profile
{
    /**
     * @param  array<string, TableRule>  $tables
     * @param  list<string>  $notPersonal  table.column entries that look personal but were judged fine to copy
     */
    public function __construct(
        public readonly string $name,
        public readonly TableMode $defaultMode = TableMode::Full,
        public readonly array $tables = [],
        public readonly array $notPersonal = [],
    ) {
        foreach ($notPersonal as $column) {
            if (! preg_match('/^[A-Za-z0-9_$-]+\.[A-Za-z0-9_]+$/', $column)) {
                throw new InvalidArgumentException("\"not_personal\" entries are table.column, got [{$column}].");
            }
        }

        if (! preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new InvalidArgumentException("Invalid profile name [{$name}].");
        }

        if (! in_array($defaultMode, [TableMode::Full, TableMode::Schema, TableMode::Skip], true)) {
            throw new InvalidArgumentException('"default_mode" must be full, schema or skip.');
        }
    }

    /**
     * The rule for $table; tables the profile doesn't list use the default mode.
     */
    public function ruleFor(string $table): TableRule
    {
        return $this->tables[$table] ?? new TableRule($this->defaultMode);
    }

    /**
     * Names of the profiles saved in $directory.
     *
     * @return list<string>
     */
    public static function names(string $directory): array
    {
        $names = array_map(fn (string $file): string => basename($file, '.json'), File::glob(rtrim($directory, '/').'/*.json'));
        sort($names);

        return array_values(array_filter($names, fn (string $name): bool => (bool) preg_match('/^[A-Za-z0-9_-]+$/', $name)));
    }

    /**
     * Columns that look personal (per the analysis) but are neither anonymized
     * nor marked as not personal, in tables this profile copies rows from.
     *
     * @return array<string, ColumnRule> table.column => suggested rule
     */
    public function undecidedPersonalData(Analysis $analysis): array
    {
        $undecided = [];

        foreach ($analysis->tables as $name => $table) {
            $rule = $this->ruleFor($name);

            if ($table->isView || in_array($rule->mode, [TableMode::Schema, TableMode::Skip], true)) {
                continue;
            }

            foreach ($table->personalData as $column => $suggestion) {
                $key = "{$name}.{$column}";

                if (! isset($rule->anonymize[$column]) && ! in_array($key, $this->notPersonal, true)) {
                    $undecided[$key] = $suggestion;
                }
            }
        }

        return $undecided;
    }

    public static function path(string $directory, string $name): string
    {
        return rtrim($directory, '/')."/{$name}.json";
    }

    public static function load(string $directory, string $name): self
    {
        $path = self::path($directory, $name);

        if (! File::exists($path)) {
            throw new RuntimeException("Profile [{$name}] not found at {$path}. Run snapshot:configure {$name} first.");
        }

        $data = json_decode(File::get($path), true);

        if (! is_array($data)) {
            throw new RuntimeException("{$path} is not valid JSON.");
        }

        try {
            $tables = [];

            foreach ($data['tables'] ?? [] as $table => $rule) {
                $tables[$table] = TableRule::fromArray($rule);
            }

            return new self($name, TableMode::from($data['default_mode'] ?? 'full'), $tables, array_values($data['not_personal'] ?? []));
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException("{$path}: ".$exception->getMessage(), previous: $exception);
        }
    }

    public function save(string $directory): string
    {
        $tables = $this->tables;
        ksort($tables);
        $notPersonal = array_values(array_unique($this->notPersonal));
        sort($notPersonal);

        $path = self::path($directory, $this->name);

        File::ensureDirectoryExists($directory);
        File::put($path, json_encode([
            'default_mode' => $this->defaultMode->value,
            ...($notPersonal === [] ? [] : ['not_personal' => $notPersonal]),
            'tables' => (object) array_map(fn (TableRule $rule): array => $rule->toArray(), $tables),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $path;
    }
}
