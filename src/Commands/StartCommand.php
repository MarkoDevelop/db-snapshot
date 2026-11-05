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

        if (! $this->ensureConnection()) {
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

        $next = select(
            label: "Profile [{$profile}] is ready. What now?",
            options: [
                'restore' => 'Pull a snapshot and restore it',
                'pull' => 'Only pull a snapshot',
                'later' => 'Nothing for now',
            ],
            default: $this->input->isInteractive() ? 'restore' : 'pull',
        );

        if ($next === 'later') {
            outro("Pull and restore later with: php artisan snapshot:refresh --profile={$profile}");

            return self::SUCCESS;
        }

        // The same questions as snapshot:refresh: where to restore, add-ons, then pull + restore unattended.
        if ($next === 'restore') {
            return $this->call('snapshot:refresh', ['--profile' => $profile]);
        }

        if ($this->call('snapshot:pull', ['--profile' => $profile]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        outro("Restore later with: php artisan snapshot:restore latest --profile={$profile}");

        return self::SUCCESS;
    }

    protected function forceAnalyze(): bool
    {
        return $this->option('analyze') || $this->option('fresh');
    }

    /**
     * Asks for missing settings, checks them over SSH, and lets the user fix
     * them until the connection works. Settings typed here are saved only
     * once they work (or when the user wants them anyway).
     */
    private function ensureConnection(): bool
    {
        $values = null;

        if (blank(config('db-snapshot.ssh.host')) || blank(config('db-snapshot.remote.database'))) {
            if (! $this->input->isInteractive()) {
                error('Set SNAPSHOT_SSH_HOST and SNAPSHOT_REMOTE_DB_DATABASE (see the README), or run php artisan snapshot interactively.');

                return false;
            }

            warning('The connection to the remote database is not configured yet.');
            $values = $this->askConnectionSettings();
        }

        while (! $this->checkConnection()) {
            if (! $this->input->isInteractive()) {
                return false;
            }

            $next = select(
                label: 'The connection failed. What now?',
                options: ['edit' => 'Edit the settings', 'retry' => 'Try again', 'quit' => 'Quit'],
            );

            if ($next === 'quit') {
                if ($values !== null) {
                    $this->offerToSave($values, 'Save these settings to %s anyway?', default: false);
                }

                return false;
            }

            if ($next === 'edit') {
                $values = $this->askConnectionSettings();
            }
        }

        if ($values !== null) {
            $this->offerToSave($values, 'Save these settings to %s?');
        }

        return true;
    }

    /**
     * Asks for every setting (the current ones as defaults) and uses them for this run.
     *
     * @return array<string, string> env key => value
     */
    private function askConnectionSettings(): array
    {
        $manager = $this->laravel->make(DriverManager::class);
        $available = $manager->available();
        $values = [];

        $values['SNAPSHOT_DRIVER'] = count($available) === 1 ? $available[0] : (string) select(
            label: 'Database driver',
            options: array_combine($available, $available),
            default: in_array(config('db-snapshot.driver'), $available, true) ? config('db-snapshot.driver') : $available[0],
        );

        $defaults = $manager->driver($values['SNAPSHOT_DRIVER'])->defaults();
        $driverChanged = $values['SNAPSHOT_DRIVER'] !== config('db-snapshot.driver');

        foreach (self::SETTINGS as $envKey => [$configKey, $label, $default]) {
            $current = (string) config("db-snapshot.{$configKey}");
            $default = match ($default) {
                ':port' => (string) $defaults['port'],
                ':username' => $defaults['username'],
                default => $default,
            };

            // Another driver's port and user would be wrong for this one.
            if ($driverChanged && in_array($configKey, ['remote.port', 'remote.username'], true)) {
                $current = '';
            }

            $values[$envKey] = text(
                label: $label,
                default: $current !== '' ? $current : $default,
                required: in_array($envKey, ['SNAPSHOT_SSH_HOST', 'SNAPSHOT_REMOTE_DB_DATABASE'], true),
                validate: $envKey === 'SNAPSHOT_SSH_KEY' ? $this->validateKeyPath(...) : null,
            );
        }

        $this->warnAboutKeyPermissions($values['SNAPSHOT_SSH_KEY']);

        // Kept passwords stay out of $values, so saving doesn't copy one from the environment into .env.
        if ((string) config('db-snapshot.remote.password') === '' || ! confirm('Keep the current database password?')) {
            $values['SNAPSHOT_REMOTE_DB_PASSWORD'] = password(
                label: 'Database password',
                hint: 'Leave empty to use the client config of the server user (e.g. ~/.my.cnf).',
            );
            config()->set('db-snapshot.remote.password', $values['SNAPSHOT_REMOTE_DB_PASSWORD']);
        }

        foreach (self::SETTINGS as $envKey => [$configKey]) {
            config()->set("db-snapshot.{$configKey}", $configKey === 'ssh.port' || $configKey === 'remote.port' ? (int) $values[$envKey] : $values[$envKey]);
        }

        config()->set('db-snapshot.driver', $values['SNAPSHOT_DRIVER']);
        $manager->forgetDrivers();

        return $values;
    }

    private function validateKeyPath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $local = $this->expandHome($path);

        return match (true) {
            ! file_exists($local) => "There is no file at {$path} on this machine. Use the path as seen where artisan runs (in Docker: inside the container).",
            is_dir($local) => "{$path} is a directory; point at the private key file.",
            ! is_readable($local) => "{$path} exists but can't be read by this user.",
            default => null,
        };
    }

    private function warnAboutKeyPermissions(string $path): void
    {
        $local = $this->expandHome($path);

        if ($path !== '' && is_file($local) && (fileperms($local) & 0o077) !== 0) {
            warning("{$path} can be read by other users, and ssh refuses such keys. Run: chmod 600 {$path}");
        }
    }

    /**
     * ssh expands ~ in -i itself; PHP's file functions don't.
     */
    private function expandHome(string $path): string
    {
        $home = (string) ($_SERVER['HOME'] ?? getenv('HOME'));

        return $home !== '' && ($path === '~' || str_starts_with($path, '~/')) ? $home.substr($path, 1) : $path;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function offerToSave(array $values, string $question, bool $default = true): void
    {
        $envPath = $this->laravel->environmentFilePath();

        if (confirm(sprintf($question, $envPath), default: $default)) {
            (new EnvFile($envPath))->set($values);
            info('Saved.');
        }
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
