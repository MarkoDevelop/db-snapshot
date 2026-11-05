<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Analysis\Analyzer;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;
use Overthink\DbSnapshot\Snapshot\Puller;
use Overthink\DbSnapshot\Snapshot\Restorer;
use Overthink\DbSnapshot\Snapshot\Snapshot;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class RefreshTableCommand extends Command
{
    use AsksTableRules;
    use ChoosesProfile;
    use PicksAddons;
    use TargetsLocalDatabase;
    use UsesAnalysis;

    private const FROM_SERVER = "\0server";

    private const NEW_ADDON = "\0new";

    protected $signature = 'snapshot:refresh-table
        {tables?* : Tables to refresh (asked when omitted)}
        {--profile= : Profile whose rules are the defaults (asked when there are several)}
        {--database= : Refresh tables in this database instead of the connection\'s own}
        {--parallel= : How many tables to dump and import at a time (default: SNAPSHOT_PARALLEL)}
        {--save= : Also keep the pulled tables in this add-on (asked when given without a name); snapshot:restore can apply it}
        {--from= : Load the tables from this saved add-on instead of pulling them; without a name, pick from a list}
        {--keep : Keep the pulled files in the snapshot directory}
        {--force : Do not ask before replacing the tables}
        {--analyze : Re-read the remote database first}';

    protected $description = 'Pull a few tables from the remote database (or a saved add-on) and replace only them locally';

    public function handle(Analyzer $analyzer, Puller $puller, Restorer $restorer, SnapshotRepository $snapshots): int
    {
        if ($this->refusesProduction()) {
            return self::FAILURE;
        }

        $save = filled($this->option('save')) ? (string) $this->option('save') : null;
        $askWhereToSave = $save === null && $this->input->hasParameterOption('--save');

        if (($save !== null || $askWhereToSave) && $this->input->hasParameterOption('--from')) {
            error('--save and --from don\'t go together: --from loads a saved add-on, --save keeps a new pull.');

            return self::FAILURE;
        }

        if ($askWhereToSave && ! $this->input->isInteractive()) {
            error('--save needs an add-on name when not interactive.');

            return self::FAILURE;
        }

        if ($save !== null && ! $this->isValidAddonName($save)) {
            error("Invalid add-on name [{$save}]: use letters, digits, - and _.");

            return self::FAILURE;
        }

        try {
            $addon = $this->chooseSource($snapshots, $save !== null || $askWhereToSave);
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        return $addon !== null
            ? $this->loadFromAddon($addon, $restorer)
            : $this->pullFromServer($analyzer, $puller, $restorer, $snapshots, $save, $askWhereToSave);
    }

    /**
     * The add-on to load from (--from, or asked when add-ons exist), or null to pull from the server.
     */
    private function chooseSource(SnapshotRepository $snapshots, bool $saving): ?Snapshot
    {
        if (filled($this->option('from'))) {
            return $snapshots->findAddon((string) $this->option('from'));
        }

        if ($this->wantsAddonPicker('from')) {
            if (! $this->input->isInteractive()) {
                throw new RuntimeException('--from needs an add-on name when not interactive.');
            }

            return $this->pickAddon($snapshots->addons(), 'Load from which add-on?');
        }

        if (! $this->input->isInteractive() || $this->argument('tables') !== [] || $saving || ($addons = $snapshots->addons()) === []) {
            return null;
        }

        $options = [self::FROM_SERVER => 'Pull them from the server'];

        foreach ($addons as $addon) {
            $options[$addon->name()] = 'Add-on '.$this->addonLabel($addon);
        }

        $source = (string) select(label: 'Where should the tables come from?', options: $options, scroll: 10);

        return $source === self::FROM_SERVER ? null : $snapshots->findAddon($source);
    }

    private function loadFromAddon(Snapshot $addon, Restorer $restorer): int
    {
        $tables = $this->argument('tables');
        $missing = array_diff($tables, $addon->tables());

        if ($missing !== []) {
            error("Add-on {$addon->profile()} has no ".implode(', ', $missing).'. It has '.implode(', ', $addon->tables()).'.');

            return self::FAILURE;
        }

        if ($tables === []) {
            $tables = count($addon->tables()) > 1 && $this->input->isInteractive()
                ? array_map(strval(...), multiselect(
                    label: "Tables to load from {$addon->profile()}",
                    options: array_combine($addon->tables(), $addon->tables()),
                    default: $addon->tables(),
                    scroll: 15,
                    required: true,
                ))
                : $addon->tables();
        }

        note("Add-on {$this->addonLabel($addon)}");

        $connection = $this->localConnection();
        $connection['database'] = $this->option('database') ?: $connection['database'];

        if (! $this->option('force') && ! confirm('Replace '.implode(', ', $tables)." in {$connection['database']}?", default: false)) {
            info('Nothing changed.');

            return self::FAILURE;
        }

        try {
            $warnings = $restorer->importTables($addon, $connection, $this->parallel(), $this->progressReporter(), $tables);
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($warnings as $message) {
            warning($message);
        }

        info('Loaded '.implode(', ', $tables)." from {$addon->profile()} into {$connection['database']}.");

        return self::SUCCESS;
    }

    private function pullFromServer(Analyzer $analyzer, Puller $puller, Restorer $restorer, SnapshotRepository $snapshots, ?string $save, bool $askWhereToSave): int
    {
        try {
            $analysis = $this->analysis($analyzer, required: true);
            $profile = $this->profile();
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        $tables = array_filter($analysis->tables, fn (TableInfo $table): bool => ! $table->isView);
        $names = $this->argument('tables') ?: multisearch(
            label: 'Tables to refresh (type to search)',
            options: fn (string $search): array => array_map(
                fn (TableInfo $table): string => $this->tableLabel($table, $profile->tables[$table->name] ?? null),
                array_filter($tables, fn (TableInfo $table): bool => $search === '' || str_contains($table->name, $search)),
            ),
            scroll: 15,
            required: true,
        );

        $unknown = array_diff($names, array_keys($tables));

        if ($unknown !== []) {
            error('Unknown tables: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $followers = $this->followersOf($profile, $names, $tables);

        if ($followers !== [] && $this->input->isInteractive()
            && confirm('Also refresh '.implode(', ', $followers).'? They follow '.implode(', ', $names).'.', default: true)) {
            $names = [...$names, ...$followers];
        } elseif ($followers !== []) {
            note(implode(', ', $followers).' follow '.implode(', ', $names).'; name them too to refresh them.');
            $followers = [];
        }

        $rules = [];

        foreach ($names as $name) {
            $rule = $profile->ruleFor($name);

            if ($rule->mode === TableMode::Skip) {
                $rule = new TableRule(TableMode::Full);
            }

            $rules[$name] = $this->input->isInteractive() && $this->argument('tables') === [] && ! in_array($name, $followers, true)
                ? $this->askRule($tables[$name], $rule, array_diff_key([...$profile->tables, ...$rules], [$name => true]))
                : $rule;

            note("{$name}: {$rules[$name]->describe()}");
        }

        $rules = array_filter($rules, fn (TableRule $rule): bool => $rule->mode !== TableMode::Skip);

        if ($rules === []) {
            info('Nothing to refresh.');

            return self::SUCCESS;
        }

        $connection = $this->localConnection();
        $connection['database'] = $this->option('database') ?: $connection['database'];

        if ($askWhereToSave) {
            $save = $this->askWhereToSave($snapshots, array_keys($rules));
        }

        if (! $this->option('force')) {
            if ($save === null && $this->input->isInteractive() && ! $this->option('keep')
                && confirm('Also save these tables as an add-on, to apply on top of later restores?', default: false)) {
                $save = $this->askWhereToSave($snapshots, array_keys($rules));
            }

            if (! confirm('Replace '.implode(', ', array_keys($rules))." in {$connection['database']}?", default: false)) {
                info('Nothing changed.');

                return self::FAILURE;
            }
        }

        if ($save !== null && ($mismatch = $this->addonOfOtherDriver($snapshots, $save)) !== null) {
            error("Add-on {$save} was pulled with the {$mismatch->driver()} driver; save to another name.");

            return self::FAILURE;
        }

        $parallel = $this->parallel();
        $report = $this->progressReporter();

        $snapshot = null;
        $warnings = [];

        try {
            // The whole profile goes along, so followers see their parents' rules.
            $pulled = new Profile($save ?? 'refresh', $profile->defaultMode, [...$profile->tables, ...$rules]);
            $this->warnAboutEmptyFollowers($pulled, array_keys($rules));

            $snapshot = $puller->pull(
                $pulled,
                $parallel,
                $report,
                partial: true,
                addon: $save !== null,
                only: array_keys($rules),
            );

            // Merged right away, so the add-on is whole even if the local import fails.
            $saved = $save !== null ? $snapshots->mergeOlderAddons($snapshot) : null;
            $warnings = $restorer->importTables($snapshot, $connection, $parallel, $report, array_keys($rules));
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($snapshot !== null && $save === null && ! $this->option('keep')) {
                File::deleteDirectory($snapshot->path);
            }
        }

        foreach ($warnings as $message) {
            warning($message);
        }

        info('Refreshed '.implode(', ', array_keys($rules))." in {$connection['database']}.");

        if ($saved !== null) {
            info(sprintf(
                'Saved to add-on %s (%s). Apply it with snapshot:restore --addon=%s, or load it with snapshot:refresh-table --from=%s.',
                $save,
                implode(', ', $saved->tables()),
                $save,
                $save,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * An existing add-on to add the tables to, or a new one.
     *
     * @param  list<string>  $tables
     */
    private function askWhereToSave(SnapshotRepository $snapshots, array $tables): string
    {
        $options = [];

        foreach ($snapshots->addons() as $addon) {
            if ($addon->driver() === config('db-snapshot.driver')) {
                $options[$addon->profile()] ??= 'Add-on '.$this->addonLabel($addon);
            }
        }

        if ($options !== []) {
            $target = (string) select(
                label: 'Save to which add-on?',
                options: [...$options, self::NEW_ADDON => 'A new add-on…'],
                hint: 'Tables it already has are replaced; its other tables stay.',
                scroll: 10,
            );

            if ($target !== self::NEW_ADDON) {
                return $target;
            }
        }

        return text(
            label: 'Add-on name',
            default: isset($options[$tables[0]]) ? '' : $tables[0],
            required: true,
            validate: fn (string $name): ?string => match (true) {
                ! $this->isValidAddonName($name) => 'Use letters, digits, - and _.',
                isset($options[$name]) => "Add-on {$name} exists; pick it from the list to add to it.",
                default => null,
            },
        );
    }

    private function addonOfOtherDriver(SnapshotRepository $snapshots, string $name): ?Snapshot
    {
        foreach ($snapshots->addons() as $addon) {
            if ($addon->profile() === $name && $addon->driver() !== config('db-snapshot.driver')) {
                return $addon;
            }
        }

        return null;
    }

    /**
     * Tables following any of $names (directly or through each other) that
     * aren't picked already.
     *
     * @param  list<string>  $names
     * @param  array<string, TableInfo>  $tables
     * @return list<string>
     */
    private function followersOf(Profile $profile, array $names, array $tables): array
    {
        $followers = [];

        foreach ($names as $name) {
            foreach ($profile->followers($name) as $follower) {
                if (isset($tables[$follower]) && ! in_array($follower, [...$names, ...$followers], true)) {
                    $followers[] = $follower;
                }
            }
        }

        return $followers;
    }

    private function profile(): Profile
    {
        $name = $this->chooseProfile($this->option('profile'));

        if (File::exists(Profile::path(config('db-snapshot.profile_path'), $name))) {
            return Profile::load(config('db-snapshot.profile_path'), $name);
        }

        note("Profile [{$name}] doesn't exist, so tables start from a full copy.");

        return new Profile($name);
    }
}
