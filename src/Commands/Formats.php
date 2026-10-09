<?php

namespace Overthink\DbSnapshot\Commands;

trait Formats
{
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
