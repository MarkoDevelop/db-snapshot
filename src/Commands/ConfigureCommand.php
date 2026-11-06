<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Laravel\Prompts\MultiSearchPrompt;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\Analyzer;
use Overthink\DbSnapshot\Analysis\ColumnInfo;
use Overthink\DbSnapshot\Analysis\Relation;
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
    use ChoosesProfile;
    use UsesAnalysis;

    protected $signature = 'snapshot:configure
        {profile? : Profile name (asked when there are several; saved as <profile>.json)}
        {--profile= : The same as the profile argument}
        {--from= : Start from a copy of this profile}
        {--default-mode= : What happens to tables without a rule: full, schema or skip (default: the profile\'s, else full)}
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

        [$profileName, $copyFrom] = $this->pickProfile($this->argument('profile') ?: $this->option('profile'), offerNew: true, askWithOne: true);
        $copyFrom = $this->option('from') ?: $copyFrom;
        $profileDirectory = config('db-snapshot.profile_path');
        $source = $copyFrom ?? $profileName;
        $loaded = File::exists(Profile::path($profileDirectory, $source)) ? Profile::load($profileDirectory, $source) : null;

        if ($copyFrom !== null && $loaded === null) {
            error("Profile [{$copyFrom}] doesn't exist, so there is nothing to copy.");

            return self::FAILURE;
        }

        $defaultMode = $loaded->defaultMode ?? TableMode::Full;

        if (filled($this->option('default-mode'))) {
            $defaultMode = TableMode::tryFrom((string) $this->option('default-mode'));

            if (! in_array($defaultMode, [TableMode::Full, TableMode::Schema, TableMode::Skip], true)) {
                error('--default-mode must be full, schema or skip.');

                return self::FAILURE;
            }
        }

        $existing = new Profile($profileName, $defaultMode, $loaded->tables ?? [], $loaded->notPersonal ?? []);

        if ($copyFrom !== null) {
            note("Starting from a copy of [{$copyFrom}]; it stays as it is.");
        }

        $rules = $this->existingRules($existing, $analysis);

        if ($analysis->tables !== [] && array_filter($analysis->tables, fn (TableInfo $table): bool => $table->relations !== []) === []) {
            note('The analysis knows no relations between tables, so "follow" isn\'t offered. If it predates them, run snapshot:analyze.');
        }
        $largeMb = (int) config('db-snapshot.large_table_mb');
        $baseTables = array_filter($analysis->tables, fn (TableInfo $table): bool => ! $table->isView);

        // Large tables and tables that already have a rule are shown before typing,
        // with the rules preselected; typing searches every table.
        $listed = array_filter($baseTables, fn (TableInfo $table): bool => $table->bytes() >= $largeMb * 1024 * 1024 || isset($rules[$table->name]));
        $label = fn (TableInfo $table): string => $this->tableLabel($table, $rules[$table->name] ?? null);
        $unruled = self::unruledPhrase($existing->defaultMode);

        $picker = new MultiSearchPrompt(
            label: 'Which tables need their own rule?',
            // The plain fallback prompt passes null for an empty answer.
            options: fn (?string $search): array => array_map($label, trim((string) $search) === '' && $listed !== []
                ? $listed
                : array_filter($baseTables, fn (TableInfo $table): bool => str_contains(strtolower($table->name), strtolower(trim((string) $search))))),
            placeholder: 'Type to search all tables',
            scroll: 15,
            hint: ($listed === [] ? 'No table is '.$largeMb.' MB or larger. ' : "Shown: tables of {$largeMb} MB or more and tables with rules. ")
                ."Space to toggle; unselected ones are {$unruled}.",
        );
        $ruled = array_intersect_key($baseTables, $rules);
        $kept = [];

        if (MultiSearchPrompt::shouldFallback()) {
            // The plain fallback prompt (Windows, tests) can't show what's preselected,
            // so the current rules get their own question.
            $kept = $ruled === [] ? [] : array_map(strval(...), multiselect(
                label: 'Keep the rules of these tables?',
                options: array_map($label, $ruled),
                default: array_keys($ruled),
                scroll: 15,
                hint: "Unselected ones are {$unruled}.",
            ));
        } else {
            $picker->values = array_map($label, $ruled);
        }

        $selected = array_values(array_unique([...$kept, ...array_map(strval(...), $picker->prompt())]));

        $toChange = $this->rulesToChange($selected, $rules);
        $newRules = [];

        foreach ($selected as $tableName) {
            if (isset($rules[$tableName]) && ! in_array($tableName, $toChange, true)) {
                $newRules[$tableName] = $rules[$tableName];
            }
        }

        foreach ($selected as $tableName) {
            if (! isset($newRules[$tableName])) {
                $newRules[$tableName] = $this->askRule($analysis->tables[$tableName], $rules[$tableName] ?? null, $newRules, $analysis->tables);
            }
        }

        $newRules = $this->suggestFollowers($analysis, $newRules);

        $notPersonal = $existing->notPersonal;
        $newRules = config('db-snapshot.anonymize.enabled')
            ? $this->askAnonymize($analysis, $existing->defaultMode, $newRules, $rules, $notPersonal)
            : $this->keepAnonymize($existing->defaultMode, $newRules, $rules);

        $this->summary($analysis, new Profile($profileName, $existing->defaultMode, $newRules, $notPersonal));

        if (! confirm("Save profile [{$profileName}]?")) {
            info('Nothing changed.');

            return self::FAILURE;
        }

        $profile = new Profile($profileName, $existing->defaultMode, $newRules, $notPersonal);
        info('Saved '.$profile->save($profileDirectory).". Pull it with: php artisan snapshot:pull --profile={$profileName}");

        $this->offerIndexMigration($profile, $analysis);

        return self::SUCCESS;
    }

    /**
     * Of the picked tables that already have a rule, the ones to ask about
     * again. Tables without a rule are always asked about.
     *
     * @param  list<string>  $selected
     * @param  array<string, TableRule>  $rules
     * @return list<string>
     */
    private function rulesToChange(array $selected, array $rules): array
    {
        $existing = array_values(array_filter($selected, fn (string $name): bool => isset($rules[$name])));

        if ($existing === []) {
            return [];
        }

        return array_map(strval(...), multiselect(
            label: 'Change any of the current rules?',
            options: array_combine($existing, array_map(fn (string $name): string => "{$name} · {$rules[$name]->describe()}", $existing)),
            scroll: 15,
            hint: 'Enter keeps the unchecked ones as they are.',
        ));
    }

    /**
     * What happens to tables without a rule, for sentences.
     */
    private static function unruledPhrase(TableMode $defaultMode): string
    {
        return match ($defaultMode) {
            TableMode::Schema => 'copied as schema only',
            TableMode::Skip => 'skipped',
            default => 'copied in full',
        };
    }

    /**
     * Offer tables pointing at filtered tables to follow them, so orders
     * come with their items. Repeats for the followers' own children.
     *
     * @param  array<string, TableRule>  $rules
     * @return array<string, TableRule>
     */
    private function suggestFollowers(Analysis $analysis, array $rules): array
    {
        $offered = [];

        do {
            $filtered = array_keys(array_filter($rules, fn (TableRule $rule): bool => in_array($rule->mode, [TableMode::Recent, TableMode::Where, TableMode::Follow], true)));
            $candidates = [];

            foreach ($analysis->tables as $name => $table) {
                if ($table->isView || isset($rules[$name]) || isset($offered[$name])) {
                    continue;
                }

                $relations = array_values(array_filter(
                    $this->followableRelations($table, null, $rules),
                    fn (Relation $relation): bool => in_array($relation->parent, $filtered, true),
                ));

                // Date-filtered chains first: that's what following is mostly for.
                usort($relations, fn (Relation $a, Relation $b): int => $this->isRecentChain($b->parent, $rules) <=> $this->isRecentChain($a->parent, $rules));

                if ($relations !== []) {
                    $candidates[$name] = $relations[0];
                }
            }

            if ($candidates === []) {
                break;
            }

            $offered += $candidates;

            $picked = multiselect(
                label: 'These tables point at tables you filter. Copy only their rows that belong to copied rows?',
                options: array_combine(array_keys($candidates), array_map(
                    fn (string $name, Relation $relation): string => "{$name} · {$this->formatBytes($analysis->tables[$name]->bytes())} → {$relation->describe()}"
                        .($relation->indexed ? '' : ", {$relation->column} not indexed"),
                    array_keys($candidates),
                    $candidates,
                )),
                default: array_keys($candidates),
                scroll: 15,
                hint: 'Unchecked tables keep their rule (copied in full by default).',
            );

            foreach ($picked as $name) {
                $rules[$name] = $this->followRule($candidates[$name]);
            }
        } while ($picked !== []);

        return $rules;
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

    private function offerIndexMigration(Profile $profile, Analysis $analysis): void
    {
        $missing = IndexMigration::missingIndexes($profile, $analysis);

        if ($missing === []) {
            return;
        }

        warning("These columns have no index, so pulling filtered rows scans the whole table on the server:\n  "
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

    /**
     * Rough share of $table's rows the profile copies, or null when unknown.
     * A follower is guessed to keep the same share as its parent.
     */
    private function share(Analysis $analysis, Profile $profile, string $table): ?float
    {
        $rule = $profile->ruleFor($table);

        if ($rule->mode === TableMode::Follow) {
            return $this->share($analysis, $profile, (string) $rule->parent);
        }

        $info = $analysis->table($table);
        $estimate = $info !== null ? $this->estimate($info, $rule) : null;

        return match (true) {
            $estimate === null => null,
            $info->bytes() === 0 => $rule->mode === TableMode::Schema || $rule->mode === TableMode::Skip ? 0.0 : 1.0,
            default => $estimate / $info->bytes(),
        };
    }

    private function summary(Analysis $analysis, Profile $profile): void
    {
        $before = 0;
        $after = 0;
        $rows = [];

        foreach ($analysis->tables as $table) {
            $before += $table->bytes();
            $rule = $profile->ruleFor($table->name);
            $share = $this->share($analysis, $profile, $table->name);
            $estimate = $share !== null ? $table->bytes() * $share : null;
            $after += $estimate ?? $table->bytes();

            if ($rule->mode !== $profile->defaultMode || $rule->anonymize !== []) {
                $rows[] = [$table->name, $this->formatBytes($table->bytes()), $rule->describe(), $estimate !== null ? '~'.$this->formatBytes($estimate) : '?'];
            }
        }

        if ($rows === []) {
            note('All tables are '.self::unruledPhrase($profile->defaultMode).'.');
        } else {
            table(['Table', 'Now', 'Rule', 'In snapshot'], $rows);
        }

        info(sprintf(
            'Estimated snapshot: ~%s of %s (before compression). Tables without a rule are %s%s.',
            $this->formatBytes($after),
            $this->formatBytes($before),
            self::unruledPhrase($profile->defaultMode),
            $profile->defaultMode === TableMode::Full ? ' (--default-mode=schema or skip changes that)' : '',
        ));
    }
}
