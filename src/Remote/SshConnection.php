<?php

namespace Overthink\DbSnapshot\Remote;

use InvalidArgumentException;

/**
 * Builds local shell commands that run a script on the remote server.
 */
final class SshConnection
{
    /**
     * @param  list<string>  $options
     */
    public function __construct(
        public readonly string $host,
        public readonly ?string $user = null,
        public readonly int $port = 22,
        public readonly ?string $key = null,
        public readonly array $options = [],
    ) {
        if ($host === '') {
            throw new InvalidArgumentException('SNAPSHOT_SSH_HOST is not set.');
        }
    }

    /**
     * @param  array{host?: ?string, user?: ?string, port?: int, key?: ?string, options?: list<string>}  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            host: (string) ($config['host'] ?? ''),
            user: ($config['user'] ?? null) ?: null,
            port: (int) ($config['port'] ?? 22),
            key: ($config['key'] ?? null) ?: null,
            options: $config['options'] ?? [],
        );
    }

    /**
     * The local command that runs $script with bash (pipefail on) on the server.
     */
    public function command(string $script): string
    {
        $arguments = ['ssh', '-p', (string) $this->port];

        if ($this->key !== null) {
            $arguments[] = '-i';
            $arguments[] = $this->key;
        }

        foreach ($this->options as $option) {
            $arguments[] = '-o';
            $arguments[] = $option;
        }

        $arguments[] = $this->user !== null ? "{$this->user}@{$this->host}" : $this->host;
        $arguments[] = 'bash -c '.escapeshellarg('set -o pipefail; '.$script);

        return implode(' ', array_map(escapeshellarg(...), $arguments));
    }
}
