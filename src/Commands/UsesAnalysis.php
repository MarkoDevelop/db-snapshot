<?php

namespace Overthink\DbSnapshot\Commands;

use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\Analyzer;

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

        if ($analysis === null) {
            note('No analysis yet; run snapshot:analyze to see table sizes.');

            return null;
        }

        $age = $analysis->analyzedAt->diffForHumans();

        if (! $analysis->isOlderThanDays($maxAgeDays)) {
            note("Using analysis from {$age} (--analyze to refresh).");

            return $analysis;
        }

        if (! $this->input->isInteractive()) {
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

        return $analysis;
    }
}
