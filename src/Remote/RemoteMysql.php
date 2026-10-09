<?php

namespace Overthink\DbSnapshot\Remote;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only access to the source database: information_schema queries and mysqldump.
 */
final class RemoteMysql
{
    /**
     * @param  list<string>  $dumpOptions
     */
    public function __construct(
        public readonly SshConnection $ssh,
        public readonly string $database,
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 3306,
        public readonly string $username = 'root',
        public readonly ?string $password = null,
        public readonly string $nice = '',
        public readonly array $dumpOptions = [],
    ) {
        if (! preg_match('/^[A-Za-z0-9_$-]+$/', $database)) {
            throw new InvalidArgumentException('SNAPSHOT_REMOTE_DB_DATABASE is missing or invalid.');
        }
    }

    /**
     * @param  array{database?: ?string, host?: ?string, port?: int, username?: ?string, password?: ?string, nice?: ?string, dump_options?: list<string>}  $config
     */
    public static function fromConfig(SshConnection $ssh, array $config): self
    {
        return new self(
            ssh: $ssh,
            database: (string) ($config['database'] ?? ''),
            host: (string) ($config['host'] ?? '127.0.0.1'),
            port: (int) ($config['port'] ?? 3306),
            username: (string) ($config['username'] ?? 'root'),
            password: ($config['password'] ?? null) ?: null,
            nice: (string) ($config['nice'] ?? ''),
            dumpOptions: $config['dump_options'] ?? [],
        );
    }

    /**
     * Run a read-only query on the server and return its rows (NULL becomes null).
     *
     * @return list<list<?string>>
     */
    public function select(string $sql): array
    {
        $result = Process::input($this->stdin())
            ->timeout(600)
            ->run($this->ssh->command($this->script('mysql', ['--batch', '--skip-column-names', '-e', $sql])));

        if ($result->failed()) {
            throw new RuntimeException('Remote query failed: '.trim($result->errorOutput()));
        }

        $rows = [];

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $rows[] = array_map(fn (string $value): ?string => $value === 'NULL' ? null : $value, explode("\t", $line));
        }

        return $rows;
    }

    /**
     * The local shell command that streams a gzipped dump of $tables into $targetFile.
     *
     * @param  list<string>  $tables
     * @param  list<string>  $options
     */
    public function dumpCommand(array $tables, array $options, string $targetFile): string
    {
        $arguments = [...$this->dumpOptions, ...$options, $this->database, ...$tables];

        return $this->ssh->command($this->script('mysqldump', $arguments).' | gzip -1')
            .' > '.escapeshellarg($targetFile);
    }

    /**
     * What to feed the SSH session's stdin (the password line, if any).
     */
    public function stdin(): string
    {
        return $this->password !== null ? $this->password."\n" : '';
    }

    public function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /**
     * @param  list<string>  $arguments
     */
    private function script(string $binary, array $arguments): string
    {
        $command = implode(' ', array_map(escapeshellarg(...), [
            $binary,
            '--host='.$this->host,
            '--port='.$this->port,
            '--user='.$this->username,
            ...$arguments,
        ]));

        $prefix = $this->password !== null ? 'IFS= read -r MYSQL_PWD; export MYSQL_PWD; ' : '';
        $nice = $binary === 'mysqldump' && $this->nice !== '' ? $this->nice.' ' : '';

        return $prefix.$nice.$command;
    }
}
