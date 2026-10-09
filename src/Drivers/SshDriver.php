<?php

namespace Overthink\DbSnapshot\Drivers;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Overthink\DbSnapshot\Contracts\Driver;
use Overthink\DbSnapshot\Remote\SshConnection;
use RuntimeException;

/**
 * Shared plumbing for drivers whose source database is reached over SSH:
 * the password is sent on stdin and exported on the server, query output
 * is parsed as tab-separated rows, and dumps are gzipped into local files.
 */
abstract class SshDriver implements Driver
{
    /**
     * @param  array{host?: ?string, port?: ?int, username?: ?string, password?: ?string, database?: ?string, nice?: ?string}  $remote
     * @param  array{host?: ?string, user?: ?string, port?: int, key?: ?string, options?: list<string>}  $ssh
     */
    public function __construct(
        protected readonly array $ssh,
        protected readonly array $remote,
    ) {}

    /**
     * Environment variable the database's command line tools read the password from.
     */
    abstract protected function passwordVariable(): string;

    /**
     * Remote shell script that prints the rows of $sql, tab-separated, without a header.
     */
    abstract protected function queryScript(string $sql): string;

    public function database(): string
    {
        $database = (string) ($this->remote['database'] ?? '');

        if (! preg_match('/^[A-Za-z0-9_$-]+$/', $database)) {
            throw new InvalidArgumentException('SNAPSHOT_REMOTE_DB_DATABASE is missing or invalid.');
        }

        return $database;
    }

    public function remoteInput(): string
    {
        return $this->password() !== null ? $this->password()."\n" : '';
    }

    /**
     * Run a read-only query on the server and return its rows (NULL becomes null).
     *
     * @return list<list<?string>>
     */
    public function select(string $sql): array
    {
        $result = Process::input($this->remoteInput())
            ->timeout(600)
            ->run($this->connection()->command($this->withPassword($this->queryScript($sql))));

        if ($result->failed()) {
            throw new RuntimeException('Remote query failed: '.trim($result->errorOutput()));
        }

        $rows = [];

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            if ($line !== '') {
                $rows[] = array_map(fn (string $value): ?string => $value === 'NULL' ? null : $value, explode("\t", $line));
            }
        }

        return $rows;
    }

    public function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /**
     * Local command that runs $script on the server and gzips its output into $file.
     */
    protected function dumpToFile(string $script, string $file): string
    {
        $nice = (string) ($this->remote['nice'] ?? '');

        return $this->connection()->command($this->withPassword(($nice !== '' ? $nice.' ' : '').$script).' | gzip -1')
            .' > '.escapeshellarg($file);
    }

    protected function connection(): SshConnection
    {
        return SshConnection::fromConfig($this->ssh);
    }

    protected function host(): string
    {
        return (string) (($this->remote['host'] ?? null) ?: '127.0.0.1');
    }

    protected function port(): int
    {
        return (int) (($this->remote['port'] ?? null) ?: $this->defaults()['port']);
    }

    protected function username(): string
    {
        return (string) (($this->remote['username'] ?? null) ?: $this->defaults()['username']);
    }

    protected function password(): ?string
    {
        return ($this->remote['password'] ?? null) ?: null;
    }

    /**
     * @param  list<string>  $arguments
     */
    protected static function shellCommand(array $arguments): string
    {
        return implode(' ', array_map(escapeshellarg(...), $arguments));
    }

    /**
     * Runs $steps one after another, stopping at the first failure, as one command whose output can be piped.
     *
     * @param  list<string>  $steps
     */
    protected static function script(array $steps): string
    {
        return 'bash -e -o pipefail -c '.escapeshellarg(implode('; ', $steps));
    }

    protected static function printLine(string $line): string
    {
        return "printf '%s\\n' ".escapeshellarg($line);
    }

    private function withPassword(string $script): string
    {
        $variable = $this->passwordVariable();

        return $this->password() !== null ? "IFS= read -r {$variable}; export {$variable}; {$script}" : $script;
    }
}
