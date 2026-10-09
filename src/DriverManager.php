<?php

namespace Overthink\DbSnapshot;

use Illuminate\Support\Manager;
use Overthink\DbSnapshot\Contracts\Driver;
use Overthink\DbSnapshot\Drivers\MysqlDriver;
use Overthink\DbSnapshot\Drivers\PgsqlDriver;

/**
 * Resolves database drivers by name, like Laravel's own managers.
 *
 * @method Driver driver(?string $driver = null)
 */
class DriverManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('db-snapshot.driver', 'mysql');
    }

    /**
     * Names of the built-in and registered drivers.
     *
     * @return list<string>
     */
    public function available(): array
    {
        return array_values(array_unique(['mysql', 'pgsql', ...array_keys($this->customCreators)]));
    }

    protected function createMysqlDriver(): MysqlDriver
    {
        return new MysqlDriver(
            $this->config->get('db-snapshot.ssh', []),
            $this->config->get('db-snapshot.remote', []),
            $this->config->get('db-snapshot.drivers.mysql.dump_options', []),
            $this->config->get('db-snapshot.drivers.mysql.skip_gtid_purged', 'auto'),
        );
    }

    protected function createPgsqlDriver(): PgsqlDriver
    {
        return new PgsqlDriver(
            $this->config->get('db-snapshot.ssh', []),
            $this->config->get('db-snapshot.remote', []),
            $this->config->get('db-snapshot.drivers.pgsql.schema', 'public'),
            $this->config->get('db-snapshot.drivers.pgsql.dump_options', []),
            $this->config->get('db-snapshot.drivers.pgsql.maintenance_database', 'postgres'),
        );
    }
}
