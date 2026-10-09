<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\Analyzer;
use Overthink\DbSnapshot\Analysis\ColumnInfo;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Profile\AnonymizeStrategy;
use Overthink\DbSnapshot\Profile\ColumnRule;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;
use Overthink\DbSnapshot\Support\IndexMigration;
use Overthink\DbSnapshot\Support\PersonalDataColumns;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
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

        $notPersonal = $existing->notPersonal;
        $newRules = config('db-snapshot.anonymize.enabled')
            ? $this->askAnonymize($analysis, $existing->defaultMode, $newRules, $rules, $notPersonal)
            : $this->keepAnonymize($existing->defaultMode, $newRules, $rules);

        $this->summary($analysis, new Profile($profileName, $existing->defaultMode, $newRules, $notPersonal));

        $saveAs = $this->askProfileName($profileDirectory, $profileName);

        if ($saveAs === null) {
            warning('Nothing saved.');

            return self::FAILURE;
        }

        $profile = new Profile($saveAs, $existing->defaultMode, $newRules, $notPersonal);
        info('Saved '.$profile->save($profileDirectory).". Pull it with: php artisan snapshot:pull --profile={$saveAs}");

        $this->offerIndexMigration($profile, $analysis);

        return self::SUCCESS;
    }

    /**
     * Pick the columns whose values are replaced on the server, starting from
     * what the profile anonymized before plus name-based suggestions.
     *
     * Suggestions that are left unchecked go to $notPersonal, so they are
     * not suggested as new again.
     *
     * @param  array<string, TableRule>  $newRules
     * @param  array<string, TableRule>  $previousRules
     * @param  list<string>  $notPersonal  table.column entries judged fine to copy; updated in place
     * @return array<string, TableRule>
     */
    private function askAnonymize(Analysis $analysis, TableMode $defaultMode, array $newRules, array $previousRules, array &$notPersonal): array
    {
        $copied = [];

        foreach ($analysis->tables as $name => $table) {
            $rule = $newRules[$name] ?? new TableRule($defaultMode);

            if (! $table->isView && $table->columns !== [] && ! in_array($rule->mode, [TableMode::Schema, TableMode::Skip], true)) {
                $copied[$name] = $table;
            }
        }

        if ($copied === []) {
            if (array_filter($analysis->tables, fn (TableInfo $table): bool => $table->columns !== []) === []) {
                note('Re-analyze (--analyze) to see columns you can anonymize.');
            }

            return $newRules;
        }

        $previous = array_filter(array_map(fn (TableRule $rule): array => $rule->anonymize, $previousRules));
        $previousKeys = [];

        foreach ($previous as $name => $columns) {
            foreach (array_keys($columns) as $column) {
                $previousKeys[] = "{$name}.{$column}";
            }
        }

        $candidates = [];

        foreach ($copied as $name => $table) {
            foreach (array_keys($previous[$name] ?? []) as $column) {
                if ($table->column($column) === null) {
                    warning("Dropping the anonymize rule for {$name}.{$column}: the column no longer exists.");
                }
            }

            foreach ($table->columns as $column) {
                $rule = $previous[$name][$column->name]
                    ?? $table->personalData[$column->name]
                    ?? ($table->personalData === [] ? PersonalDataColumns::suggest($name, $column) : null);

                if ($rule !== null) {
                    $candidates["{$name}.{$column->name}"] = $rule;
                }
            }
        }

        $chosen = $candidates === [] ? [] : multiselect(
            label: 'Anonymize these columns (personal data)',
            options: array_combine(
                array_keys($candidates),
                array_map(
                    fn (string $key, ColumnRule $rule): string => "{$key} → {$rule->describe()}".(in_array($key, $notPersonal, true) ? ' (marked not personal)' : ''),
                    array_keys($candidates),
                    $candidates,
                ),
            ),
            default: array_values(array_filter(
                array_keys($candidates),
                fn (string $key): bool => in_array($key, $previousKeys, true) || ! in_array($key, $notPersonal, true),
            )),
            scroll: 15,
            hint: 'Replaced on the server while dumping. Unchecked ones are remembered as not personal.',
        );

        $others = [];

        foreach ($copied as $name => $table) {
            foreach ($table->columns as $column) {
                if (! $column->primaryKey && ! $column->generated && ! isset($candidates["{$name}.{$column->name}"])) {
                    $others["{$name}.{$column->name}"] = $column;
                }
            }
        }

        $added = $others === [] ? [] : multisearch(
            label: 'Any other columns to anonymize? (type to search, Enter for none)',
            options: function (string $search) use ($others): array {
                $options = [];

                foreach ($others as $key => $column) {
                    if ($search === '' || str_contains($key, $search)) {
                        $options[$key] = "{$key} ({$column->type})";
                    }
                }

                return $options;
            },
            placeholder: 'e.g. orders.customer_note',
            scroll: 15,
        );

        $notPersonal = array_values(array_unique([
            ...array_diff($notPersonal, array_keys($candidates)),
            ...array_diff(array_keys($candidates), $chosen),
        ]));

        $anonymize = [];

        foreach ($chosen as $key) {
            [$table, $column] = explode('.', (string) $key, 2);
            $anonymize[$table][$column] = $candidates[$key];
        }

        foreach ($added as $key) {
            [$table, $column] = explode('.', (string) $key, 2);
            $anonymize[$table][$column] = $this->askColumnRule((string) $key, $others[$key]);
        }

        foreach ($copied as $name => $table) {
            $rule = $newRules[$name] ?? new TableRule($defaultMode);

            if (($anonymize[$name] ?? []) !== [] || $rule->anonymize !== []) {
                $newRules[$name] = $rule->withAnonymize($anonymize[$name] ?? []);
            }
        }

        return $newRules;
    }

    /**
     * With anonymization off the step is skipped, but rules already in the
     * profile are kept, so switching it back on brings them back.
     *
     * @param  array<string, TableRule>  $newRules
     * @param  array<string, TableRule>  $previousRules
     * @return array<string, TableRule>
     */
    private function keepAnonymize(TableMode $defaultMode, array $newRules, array $previousRules): array
    {
        foreach ($previousRules as $name => $previous) {
            $rule = $newRules[$name] ?? new TableRule($defaultMode);

            if ($previous->anonymize !== [] && ! in_array($rule->mode, [TableMode::Schema, TableMode::Skip], true)) {
                $newRules[$name] = $rule->withAnonymize($previous->anonymize);
            }
        }

        return $newRules;
    }

    private function askColumnRule(string $key, ColumnInfo $column): ColumnRule
    {
        $strategies = array_filter(
            AnonymizeStrategy::cases(),
            fn (AnonymizeStrategy $strategy): bool => ($column->isText() || ! $strategy->needsTextColumn())
                && ($column->nullable || $strategy !== AnonymizeStrategy::Null),
        );

        $strategy = AnonymizeStrategy::from((string) select(
            label: "Replace {$key} ({$column->type}) with",
            options: array_combine(
                array_map(fn (AnonymizeStrategy $strategy): string => $strategy->value, $strategies),
                array_map(fn (AnonymizeStrategy $strategy): string => $strategy->label(), $strategies),
            ),
        ));

        return match ($strategy) {
            AnonymizeStrategy::Template => new ColumnRule($strategy, text(
                label: "Template for {$key}",
                default: ucfirst(str_replace('_', ' ', $column->name)).' {hash}',
                required: true,
                validate: fn (string $value): ?string => str_contains($value, '{hash}') || str_contains($value, '{id}') ? null : 'Use {hash} or {id}.',
            )),
            AnonymizeStrategy::Fixed => new ColumnRule($strategy, text(label: "Value for every {$key}", required: true)),
            default => new ColumnRule($strategy),
        };
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

            if ($rule->mode !== TableMode::Full || $rule->anonymize !== []) {
                $rows[] = [$table->name, $this->formatBytes($table->bytes()), $rule->describe(), $estimate !== null ? '~'.$this->formatBytes($estimate) : '?'];
            }
        }

        if ($rows === []) {
            note('All tables are copied in full.');
        } else {
            table(['Table', 'Now', 'Rule', 'In snapshot'], $rows);
        }

        info(sprintf(
            'Estimated snapshot: ~%s of %s (before compression; all other tables in full).',
            $this->formatBytes($after),
            $this->formatBytes($before),
        ));
    }
}
