<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Overthink\DbSnapshot\Analysis\Analyzer;
use Overthink\DbSnapshot\Contracts\Driver;
use Overthink\DbSnapshot\DriverManager;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Support\EnvFile;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class StartCommand extends Command
{
    use ChoosesProfile;
    use TargetsLocalDatabase;
    use UsesAnalysis;

    protected $signature = 'snapshot
        {--profile= : Profile to use (asked when there are several)}
        {--analyze : Re-read the remote database}
        {--fresh : Re-analyze the remote database and edit the profile, even if both exist}';

    protected $description = 'Guided setup: connect, analyze, configure a profile, pull and restore';

    /**
     * Settings the wizard asks for: env key => [config key, label, default].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const SETTINGS = [
        'SNAPSHOT_SSH_HOST' => ['ssh.host', 'SSH host', ''],
        'SNAPSHOT_SSH_USER' => ['ssh.user', 'SSH user', 'root'],
        'SNAPSHOT_SSH_PORT' => ['ssh.port', 'SSH port', '22'],
        'SNAPSHOT_SSH_KEY' => ['ssh.key', 'Path to the SSH private key (as seen by this machine)', ''],
        'SNAPSHOT_REMOTE_DB_HOST' => ['remote.host', 'Database host, as seen from the server', '127.0.0.1'],
        'SNAPSHOT_REMOTE_DB_PORT' => ['remote.port', 'Database port', ':port'],
        'SNAPSHOT_REMOTE_DB_USERNAME' => ['remote.username', 'Database user', ':username'],
        'SNAPSHOT_REMOTE_DB_DATABASE' => ['remote.database', 'Database to snapshot', ''],
    ];

    public function handle(): int
    {
        intro('DB Snapshot');

        if (! $this->ensureConnectionSettings() || ! $this->checkConnection()) {
            return self::FAILURE;
        }

        try {
            $this->analysis($this->laravel->make(Analyzer::class), required: true);
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        [$profile, $copyFrom] = $this->pickProfile($this->option('profile'), offerNew: true);

        if (! $this->ensureProfile($profile, $copyFrom)) {
            return self::FAILURE;
        }

        if (! confirm("Pull a snapshot with profile [{$profile}] now?")) {
            outro("Pull later with: php artisan snapshot:pull --profile={$profile}");

            return self::SUCCESS;
        }

        if ($this->call('snapshot:pull', ['--profile' => $profile]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $connection = $this->localConnection();

        if (! confirm("Restore it into {$connection['database']} now? This drops and recreates the database.", default: false)) {
            outro("Restore later with: php artisan snapshot:restore latest --profile={$profile}");

            return self::SUCCESS;
        }

        return $this->call('snapshot:restore', ['snapshot' => 'latest', '--profile' => $profile, '--force' => true]);
    }

    protected function forceAnalyze(): bool
    {
        return $this->option('analyze') || $this->option('fresh');
    }

    private function ensureConnectionSettings(): bool
    {
        if (filled(config('db-snapshot.ssh.host')) && filled(config('db-snapshot.remote.database'))) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            error('Set SNAPSHOT_SSH_HOST and SNAPSHOT_REMOTE_DB_DATABASE (see the README), or run php artisan snapshot interactively.');

            return false;
        }

        warning('The connection to the remote database is not configured yet.');

        $manager = $this->laravel->make(DriverManager::class);
        $available = $manager->available();
        $values = [];

        $values['SNAPSHOT_DRIVER'] = count($available) === 1 ? $available[0] : (string) select(
            label: 'Database driver',
            options: array_combine($available, $available),
            default: in_array(config('db-snapshot.driver'), $available, true) ? config('db-snapshot.driver') : $available[0],
        );

        $defaults = $manager->driver($values['SNAPSHOT_DRIVER'])->defaults();

        foreach (self::SETTINGS as $envKey => [$configKey, $label, $default]) {
            $current = (string) config("db-snapshot.{$configKey}");
            $default = match ($default) {
                ':port' => (string) $defaults['port'],
                ':username' => $defaults['username'],
                default => $default,
            };

            $values[$envKey] = text(
                label: $label,
                default: $current !== '' ? $current : $default,
                required: in_array($envKey, ['SNAPSHOT_SSH_HOST', 'SNAPSHOT_REMOTE_DB_DATABASE'], true),
            );
        }

        $values['SNAPSHOT_REMOTE_DB_PASSWORD'] = password(
            label: 'Database password',
            hint: 'Leave empty to use the client config of the server user (e.g. ~/.my.cnf).',
        );

        foreach (self::SETTINGS as $envKey => [$configKey]) {
            config()->set("db-snapshot.{$configKey}", $configKey === 'ssh.port' || $configKey === 'remote.port' ? (int) $values[$envKey] : $values[$envKey]);
        }

        config()->set('db-snapshot.remote.password', $values['SNAPSHOT_REMOTE_DB_PASSWORD']);
        config()->set('db-snapshot.driver', $values['SNAPSHOT_DRIVER']);
        $manager->forgetDrivers();

        $envPath = $this->laravel->environmentFilePath();

        if (confirm("Save these settings to {$envPath}?")) {
            (new EnvFile($envPath))->set($values);
            info('Saved.');
        }

        return true;
    }

    private function checkConnection(): bool
    {
        try {
            $driver = $this->laravel->make(Driver::class);
            $version = spin(fn (): string => $driver->serverVersion(), 'Connecting over SSH…');
        } catch (Throwable $exception) {
            error($exception->getMessage());
            warning('Check that the SSH key exists and is readable (chmod 600), that the server accepts it, and the database credentials.');

            return false;
        }

        info("Connected: {$driver->name()} {$version} on ".config('db-snapshot.ssh.host').', database '.config('db-snapshot.remote.database').'.');

        return true;
    }

    private function ensureProfile(string $profile, ?string $copyFrom): bool
    {
        $exists = file_exists(Profile::path(config('db-snapshot.profile_path'), $profile));

        $edit = ! $exists || $this->option('fresh') || select(
            label: "Profile [{$profile}] exists. What now?",
            options: ['use' => 'Use it as it is', 'edit' => 'Edit it first'],
        ) === 'edit';

        return ! $edit || $this->call('snapshot:configure', array_filter(['profile' => $profile, '--from' => $copyFrom])) === self::SUCCESS;
    }
}
