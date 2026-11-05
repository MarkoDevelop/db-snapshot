<?php

namespace Overthink\DbSnapshot\Commands;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Overthink\DbSnapshot\Snapshot\Snapshot;

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

    /**
     * "1 day ago · default · 120 MB · 2026-10-08_080000_default"
     */
    protected function snapshotLabel(Snapshot $snapshot): string
    {
        return sprintf(
            '%s · %s · %s · %s',
            $snapshot->createdAt()->diffForHumans(),
            $snapshot->profile(),
            $this->formatBytes($snapshot->bytes()),
            $snapshot->name(),
        );
    }

    /**
     * "2 hours ago · orders-full · orders, order_items · 1.2 GB"
     */
    protected function addonLabel(Snapshot $addon): string
    {
        return sprintf(
            '%s · %s · %s · %s',
            $addon->createdAt()->diffForHumans(),
            $addon->profile(),
            implode(', ', $addon->tables()),
            $this->formatBytes($addon->bytes()),
        );
    }

    /**
     * A warning when an add-on was pulled long before or after the data it
     * lands on, so its rows may point at rows that don't exist there.
     */
    protected function addonAgeWarning(Snapshot $addon, CarbonImmutable $base): ?string
    {
        $gapDays = (int) config('db-snapshot.addon_max_gap_days', 7);
        $pulledAt = $addon->createdAt();

        if (abs($addon->oldestTableAt()->diffInDays($base)) > abs($pulledAt->diffInDays($base))) {
            $pulledAt = $addon->oldestTableAt();
        }

        if (abs($pulledAt->diffInDays($base)) <= $gapDays) {
            return null;
        }

        return sprintf(
            'Add-on %s has tables pulled %s %s the snapshot, so their rows may point at rows the snapshot doesn\'t have (or the other way round).',
            $addon->profile(),
            $pulledAt->diffForHumans($base, CarbonInterface::DIFF_ABSOLUTE),
            $pulledAt->greaterThan($base) ? 'after' : 'before',
        );
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
