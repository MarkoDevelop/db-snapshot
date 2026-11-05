<?php

namespace Overthink\DbSnapshot\Commands;

use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\Analyzer;
use Overthink\DbSnapshot\Profile\ColumnRule;
use Overthink\DbSnapshot\Profile\Profile;

use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/**
 * Loads the committed analysis file and offers to refresh it when it's old.
 */
trait UsesAnalysis
{
    /**
     * Container key holding the analysis already settled in this process
     * ("none" when there was none), so commands calling each other ask once.
     */
    private const SETTLED_ANALYSIS = 'db-snapshot.settled-analysis';

    /**
     * @param  bool  $required  analyze when there is no analysis file yet
     */
    protected function analysis(Analyzer $analyzer, bool $required): ?Analysis
    {
        $path = config('db-snapshot.analysis_path');
        $maxAgeDays = (int) config('db-snapshot.analysis_max_age_days');
        $analysis = Analysis::load($path);

        if ($this->forceAnalyze() || ($analysis === null && $required)) {
            return $this->analyzeNow($analyzer, $path);
        }

        $key = $analysis?->analyzedAt->toIso8601String() ?? 'none';

        if ($this->laravel->bound(self::SETTLED_ANALYSIS) && $this->laravel->make(self::SETTLED_ANALYSIS) === $key) {
            return $analysis;
        }

        $this->laravel->instance(self::SETTLED_ANALYSIS, $key);

        if ($analysis === null) {
            note('No analysis yet; run snapshot:analyze to see table sizes.');

            return null;
        }

        $age = $analysis->analyzedAt->diffForHumans();

        if (! $analysis->isOlderThanDays($maxAgeDays)) {
            note("Using analysis from {$age} (--analyze to refresh).");

            return $analysis;
        }

        if (! $this->mayAskAboutAnalysis()) {
            warning("The analysis is from {$age}; run snapshot:analyze to refresh it.");

            return $analysis;
        }

        $choice = select(
            label: "The analysis of the remote database is from {$age}. Refresh it?",
            options: [
                'analyze' => 'Analyze now',
                'continue' => 'Continue with the current analysis',
            ],
        );

        return $choice === 'analyze' ? $this->analyzeNow($analyzer, $path) : $analysis;
    }

    /**
     * Point out columns that look personal and that $profile neither
     * anonymizes nor marks as not personal.
     */
    protected function warnAboutUndecidedPersonalData(Analysis $analysis, Profile $profile): void
    {
        $undecided = config('db-snapshot.anonymize.enabled') ? $profile->undecidedPersonalData($analysis) : [];

        if ($undecided === []) {
            return;
        }

        warning(sprintf(
            "Columns that look personal, not anonymized in profile [%s]:\n%s\nRun php artisan snapshot:configure %s to anonymize them or mark them as not personal.",
            $profile->name,
            implode("\n", array_map(fn (string $key, ColumnRule $rule): string => "  {$key} → {$rule->describe()}", array_keys($undecided), $undecided)),
            $profile->name,
        ));
    }

    /**
     * Whether an old analysis may be asked about; otherwise it's only warned about.
     */
    protected function mayAskAboutAnalysis(): bool
    {
        return $this->input->isInteractive();
    }

    protected function forceAnalyze(): bool
    {
        return (bool) $this->option('analyze');
    }

    private function analyzeNow(Analyzer $analyzer, string $path): Analysis
    {
        $analysis = spin(
            fn (): Analysis => $analyzer->analyze((int) config('db-snapshot.large_table_mb')),
            'Analyzing the remote database…',
        );

        $analysis->save($path);
        $this->laravel->instance(self::SETTLED_ANALYSIS, $analysis->analyzedAt->toIso8601String());

        return $analysis;
    }
}
