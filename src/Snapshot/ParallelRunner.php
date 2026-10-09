<?php

namespace Overthink\DbSnapshot\Snapshot;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Sleep;

/**
 * Runs shell commands with at most N of them at a time, in the given order.
 */
final class ParallelRunner
{
    /**
     * @param  array<string, array{command: string|list<string>, process: PendingProcess}>  $jobs
     * @param  (Closure(string, ProcessResult, float): void)|null  $onFinished
     * @param  (Closure(list<string>): void)|null  $onTick  receives the keys still running
     * @return array<string, ProcessResult>
     */
    public function run(array $jobs, int $concurrency, ?Closure $onFinished = null, ?Closure $onTick = null): array
    {
        $concurrency = max(1, $concurrency);
        $pending = $jobs;

        /** @var array<string, array{process: InvokedProcess, started: float}> $running */
        $running = [];
        $results = [];

        while ($pending !== [] || $running !== []) {
            while ($pending !== [] && count($running) < $concurrency) {
                $key = array_key_first($pending);
                $job = $pending[$key];
                unset($pending[$key]);

                $running[$key] = ['process' => $job['process']->start($job['command']), 'started' => microtime(true)];
            }

            foreach ($running as $key => $entry) {
                if ($entry['process']->running()) {
                    continue;
                }

                $results[$key] = $entry['process']->wait();
                unset($running[$key]);

                if ($onFinished !== null) {
                    $onFinished($key, $results[$key], microtime(true) - $entry['started']);
                }
            }

            if ($running !== []) {
                if ($onTick !== null) {
                    $onTick(array_keys($running));
                }

                Sleep::usleep(250_000);
            }
        }

        return $results;
    }
}
