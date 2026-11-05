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

        foreach (array_keys($tables) as $table) {
            $this->followChain((string) $table);
        }
    }

    /**
     * The tables $table follows, nearest first.
     *
     * @return list<string>
     */
    public function followChain(string $table): array
    {
        $chain = [];
        $current = $table;

        while (($rule = $this->tables[$current] ?? null)?->mode === TableMode::Follow) {
            $current = (string) $rule->parent;

            if ($current === $table || in_array($current, $chain, true)) {
                throw new InvalidArgumentException('"follow" rules go in a circle: '.implode(' → ', [$table, ...$chain, $current]).'.');
            }

            $chain[] = $current;
        }

        return $chain;
    }

    /**
     * Followers whose chain starts at a table that copies no rows (schema or
     * skip, also through default_mode), so they get no rows either.
     *
     * @param  ?list<string>  $tables  only these followers
     * @return array<string, string> follower => the table copying no rows
     */
    public function emptyFollowers(?array $tables = null): array
    {
        $empty = [];

        foreach ($this->tables as $name => $rule) {
            $chain = $rule->mode === TableMode::Follow ? $this->followChain((string) $name) : [];
            $root = $chain[array_key_last($chain) ?? 0] ?? null;

            if ($root !== null && ($tables === null || in_array($name, $tables, true))
                && in_array($this->ruleFor($root)->mode, [TableMode::Schema, TableMode::Skip], true)) {
                $empty[(string) $name] = $root;
            }
        }

        return $empty;
    }

    /**
     * Tables that follow $table, directly or through other followers.
     *
     * @return list<string>
     */
    public function followers(string $table): array
    {
        $followers = array_keys(array_filter(
            $this->tables,
            fn (TableRule $rule, string $name): bool => $rule->mode === TableMode::Follow && in_array($table, $this->followChain($name), true),
            ARRAY_FILTER_USE_BOTH,
        ));
        sort($followers);

        return $followers;
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
