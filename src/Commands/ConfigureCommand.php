<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\Analyzer;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;
use Overthink\DbSnapshot\Support\IndexMigration;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class ConfigureCommand extends Command
{
    use AsksTableRules;
    use UsesAnalysis;

    protected $signature = 'snapshot:configure
        {profile=default : Profile name (saved as <profile>.json)}
        {--analyze : Re-read the remote database first}';

    protected $description = 'Choose which tables a snapshot contains and how much of each';

    public function handle(Analyzer $analyzer): int
    {
        try {
            $analysis = $this->analysis($analyzer, required: true);
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        $profileName = (string) $this->argument('profile');
        $profileDirectory = config('db-snapshot.profile_path');
        $existing = File::exists(Profile::path($profileDirectory, $profileName))
            ? Profile::load($profileDirectory, $profileName)
            : new Profile($profileName);

        $rules = $this->existingRules($existing, $analysis);
        $largeBytes = (int) config('db-snapshot.large_table_mb') * 1024 * 1024;
        $baseTables = array_filter($analysis->tables, fn (TableInfo $table): bool => ! $table->isView);

        $large = array_filter($baseTables, fn (TableInfo $table): bool => $table->bytes() >= $largeBytes);
        $selected = $large === [] ? [] : multiselect(
            label: 'Large tables that should NOT be copied in full',
            options: array_map(fn (TableInfo $table): string => $this->tableLabel($table, $rules[$table->name] ?? null), $large),
            default: array_values(array_intersect(array_keys($large), array_keys($rules))),
            scroll: 15,
            hint: 'Space to toggle. Unselected tables are copied in full.',
        );

        if ($large === []) {
            note('No table is '.config('db-snapshot.large_table_mb').' MB or larger.');
        }

        $others = array_diff_key($baseTables, $large);
        $selected = [...$selected, ...multisearch(
            label: 'Any smaller tables to filter, empty or skip? (type to search, Enter for none)',
            options: fn (string $search): array => array_map(
                fn (TableInfo $table): string => $this->tableLabel($table, $rules[$table->name] ?? null),
                array_filter($others, fn (TableInfo $table): bool => $search === '' || str_contains($table->name, $search)),
            ),
            placeholder: 'e.g. failed_jobs',
            scroll: 15,
        )];

        $smallWithRules = array_intersect_key($rules, $others);

        if ($smallWithRules !== [] && confirm(
            label: 'Keep the existing rules for '.implode(', ', array_keys($smallWithRules)).'?',
        )) {
            $selected = array_values(array_unique([...$selected, ...array_keys($smallWithRules)]));
        }

        $newRules = [];

        foreach ($selected as $tableName) {
            $newRules[$tableName] = $this->askRule($analysis->tables[$tableName], $rules[$tableName] ?? null);
        }

        $this->summary($analysis, new Profile($profileName, $existing->defaultMode, $newRules));

        $saveAs = $this->askProfileName($profileDirectory, $profileName);

        if ($saveAs === null) {
            warning('Nothing saved.');

            return self::FAILURE;
        }

        $profile = new Profile($saveAs, $existing->defaultMode, $newRules);
        info('Saved '.$profile->save($profileDirectory).". Pull it with: php artisan snapshot:pull --profile={$saveAs}");

        $this->offerIndexMigration($profile, $analysis);

        return self::SUCCESS;
    }

    private function askProfileName(string $directory, string $current): ?string
    {
        $name = text(
            label: 'Save as profile',
            default: $current,
            required: true,
            validate: fn (string $value): ?string => preg_match('/^[A-Za-z0-9_-]+$/', $value) ? null : 'Use letters, digits, - and _ only.',
            hint: 'Saved to '.Profile::path($directory, '<name>'),
        );

        if ($name !== $current && File::exists(Profile::path($directory, $name))
            && ! confirm("Profile [{$name}] already exists. Overwrite it?", default: false)) {
            return null;
        }

        return $name;
    }

    private function offerIndexMigration(Profile $profile, Analysis $analysis): void
    {
        $missing = IndexMigration::missingIndexes($profile, $analysis);

        if ($missing === []) {
            return;
        }

        warning("These date columns have no index, so pulling recent rows scans the whole table on the server:\n  "
            .implode("\n  ", array_map(
                fn (string $table, array $columns): string => $table.'.'.implode(', '.$table.'.', $columns)
                    .' ('.$this->formatBytes((int) $analysis->table($table)?->bytes()).')',
                array_keys($missing),
                $missing,
            )));

        if (! confirm('Generate a migration that adds these indexes?', default: false)) {
            return;
        }

        info('Created '.IndexMigration::write(database_path('migrations'), $missing).'. Review it, then deploy it so the server gets the indexes.');
    }

    /**
     * @return array<string, TableRule>
     */
    private function existingRules(Profile $profile, Analysis $analysis): array
    {
        $missing = array_diff(array_keys($profile->tables), array_keys($analysis->tables));

        if ($missing !== []) {
            warning('Dropping rules for tables that no longer exist: '.implode(', ', $missing));
        }

        return array_intersect_key($profile->tables, $analysis->tables);
    }

    private function summary(Analysis $analysis, Profile $profile): void
    {
        $before = 0;
        $after = 0;
        $rows = [];

        foreach ($analysis->tables as $table) {
            $before += $table->bytes();
            $rule = $profile->ruleFor($table->name);
            $estimate = $this->estimate($table, $rule);
            $after += $estimate ?? $table->bytes();

            if ($rule->mode !== TableMode::Full) {
                $rows[] = [$table->name, $this->formatBytes($table->bytes()), $rule->describe(), $estimate !== null ? '~'.$this->formatBytes($estimate) : '?'];
            }
        }

        table(['Table', 'Now', 'Rule', 'In snapshot'], $rows);

        info(sprintf(
            'Estimated snapshot: ~%s of %s (before compression; all other tables in full).',
            $this->formatBytes($after),
            $this->formatBytes($before),
        ));
    }
}
