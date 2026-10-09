<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Analysis\Analyzer;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Snapshot\Puller;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

class PullCommand extends Command
{
    use Formats;
    use UsesAnalysis;

    protected $signature = 'snapshot:pull
        {--profile=default : Profile to pull}
        {--parallel= : Parallel SSH sessions (default: config db-snapshot.parallel)}
        {--analyze : Re-read the remote database first}';

    protected $description = 'Stream a snapshot of the remote database into the local snapshot directory';

    public function handle(Puller $puller, Analyzer $analyzer): int
    {
        $startedAt = microtime(true);
        $lastReport = $startedAt;
        $parallel = (int) ($this->option('parallel') ?: config('db-snapshot.parallel'));

        try {
            $analysis = $this->analysis($analyzer, required: false);

            $profileName = (string) $this->option('profile');

            if (! file_exists(Profile::path(config('db-snapshot.profile_path'), $profileName))
                && $this->input->isInteractive()
                && confirm("Profile [{$profileName}] doesn't exist yet. Create it now?")
                && $this->call('snapshot:configure', ['profile' => $profileName]) !== self::SUCCESS) {
                return self::FAILURE;
            }

            $profile = Profile::load(config('db-snapshot.profile_path'), $profileName);

            if ($analysis !== null) {
                $this->warnAboutUndecidedPersonalData($analysis, $profile);
            }

            $ignored = array_keys(array_filter($profile->tables, fn ($rule): bool => $rule->anonymize !== []));

            if ($ignored !== [] && ! config('db-snapshot.anonymize.enabled')) {
                note('Anonymization is off (SNAPSHOT_ANONYMIZE), so these tables are copied as they are: '.implode(', ', $ignored));
            }

            note("Pulling profile [{$profile->name}] with {$parallel} parallel sessions…");

            $snapshot = $puller->pull(
                $profile,
                $parallel,
                function (string $key, ProcessResult $result, float $seconds): void {
                    $this->line(sprintf('  %s %-40s %6.1fs', $result->successful() ? '<info>✓</info>' : '<error>✗</error>', $key, $seconds));
                },
                function (array $running, string $directory) use (&$lastReport): void {
                    if (microtime(true) - $lastReport < 15) {
                        return;
                    }

                    $lastReport = microtime(true);
                    $bytes = array_sum(array_map(fn (string $file): int => (int) @filesize($file), File::allFiles($directory)));
                    $this->line(sprintf('  <comment>… %s downloaded, running: %s</comment>', $this->formatBytes($bytes), implode(', ', $running)));
                },
            );
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        info(sprintf(
            'Snapshot %s: %s compressed in %ds. Restore with: php artisan snapshot:restore %s',
            $snapshot->name(),
            $this->formatBytes($snapshot->bytes()),
            (int) (microtime(true) - $startedAt),
            $snapshot->name(),
        ));

        return self::SUCCESS;
    }
}
