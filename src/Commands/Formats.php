<?php

namespace Overthink\DbSnapshot\Commands;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;

trait Formats
{
    /**
     * Prints one "✓ table  1.2s" line per finished dump or import.
     *
     * @return Closure(string, ProcessResult, float): void
     */
    protected function progressReporter(): Closure
    {
        return function (string $key, ProcessResult $result, float $seconds): void {
            $this->line(sprintf('  %s %-40s %6.1fs', $result->successful() ? '<info>✓</info>' : '<error>✗</error>', $key, $seconds));
        };
    }

    /**
     * --parallel, or SNAPSHOT_PARALLEL.
     */
    protected function parallel(): int
    {
        return max(1, (int) ($this->option('parallel') ?: config('db-snapshot.parallel')));
    }

    protected function formatBytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $bytes : number_format($bytes, $bytes < 10 ? 1 : 0)).' '.$units[$unit];
    }

    protected function formatRows(int|float $rows): string
    {
        return match (true) {
            $rows >= 1_000_000 => number_format($rows / 1_000_000, 1).'M',
            $rows >= 1_000 => number_format($rows / 1_000, 1).'K',
            default => (string) (int) $rows,
        };
    }
}
