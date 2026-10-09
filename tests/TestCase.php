<?php

namespace Overthink\DbSnapshot\Tests;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use Overthink\DbSnapshot\DbSnapshotServiceProvider;

abstract class TestCase extends Orchestra
{
    protected string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/db-snapshot-tests-'.bin2hex(random_bytes(4));

        parent::setUp();

        File::ensureDirectoryExists($this->workspace);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [DbSnapshotServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('db-snapshot.ssh', [
            'host' => 'db.example.test',
            'user' => 'deploy',
            'port' => 2222,
            'key' => '/keys/snapshot',
            'options' => ['BatchMode=yes'],
        ]);

        $app['config']->set('db-snapshot.remote', [
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'reader',
            'password' => 's3cret',
            'database' => 'production',
            'nice' => '',
        ]);

        $app['config']->set('db-snapshot.drivers.mysql.dump_options', ['--single-transaction']);

        $app['config']->set('db-snapshot.path', $this->workspace.'/snapshots');
        $app['config']->set('db-snapshot.profile_path', $this->workspace.'/profiles');
        $app['config']->set('db-snapshot.analysis_path', $this->workspace.'/analysis.json');
        $app['config']->set('db-snapshot.large_table_mb', 100);
        $app['config']->set('db-snapshot.analysis_max_age_days', 30);
        $app['config']->set('db-snapshot.anonymize.enabled', true);
    }
}
