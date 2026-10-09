<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Overthink\DbSnapshot\Snapshot\Snapshot;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

class ListCommand extends Command
{
    use Formats;

    protected $signature = 'snapshot:list';

    protected $description = 'List pulled snapshots';

    public function handle(SnapshotRepository $snapshots): int
    {
        $all = $snapshots->all();

        if ($all === []) {
            info("No snapshots in {$snapshots->path} yet.");

            return self::SUCCESS;
        }

        table(['Snapshot', 'Profile', 'Tables', 'Size', 'Pulled'], array_map(fn (Snapshot $snapshot): array => [
            $snapshot->name(),
            $snapshot->profile(),
            (string) count($snapshot->manifest['tables']),
            $this->formatBytes($snapshot->bytes()),
            $snapshot->createdAt()->diffForHumans(),
        ], $all));

        return self::SUCCESS;
    }
}
