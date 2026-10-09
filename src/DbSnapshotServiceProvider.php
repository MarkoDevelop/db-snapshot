<?php

namespace Overthink\DbSnapshot;

use Illuminate\Contracts\Foundation\Application;
use Overthink\DbSnapshot\Analysis\Analyzer;
use Overthink\DbSnapshot\Commands\AnalyzeCommand;
use Overthink\DbSnapshot\Commands\ConfigureCommand;
use Overthink\DbSnapshot\Commands\ListCommand;
use Overthink\DbSnapshot\Commands\PullCommand;
use Overthink\DbSnapshot\Commands\RefreshCommand;
use Overthink\DbSnapshot\Commands\RefreshTableCommand;
use Overthink\DbSnapshot\Commands\RestoreCommand;
use Overthink\DbSnapshot\Commands\StartCommand;
use Overthink\DbSnapshot\Remote\RemoteMysql;
use Overthink\DbSnapshot\Remote\SshConnection;
use Overthink\DbSnapshot\Snapshot\ParallelRunner;
use Overthink\DbSnapshot\Snapshot\Puller;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DbSnapshotServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('db-snapshot')
            ->hasConfigFile()
            ->hasCommands([
                StartCommand::class,
                AnalyzeCommand::class,
                ConfigureCommand::class,
                PullCommand::class,
                RestoreCommand::class,
                RefreshCommand::class,
                RefreshTableCommand::class,
                ListCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(SshConnection::class, fn (Application $app): SshConnection => SshConnection::fromConfig($app['config']['db-snapshot.ssh']));

        $this->app->bind(RemoteMysql::class, fn (Application $app): RemoteMysql => RemoteMysql::fromConfig(
            $app->make(SshConnection::class),
            $app['config']['db-snapshot.remote'],
        ));

        $this->app->bind(Puller::class, fn (Application $app): Puller => new Puller(
            $app->make(RemoteMysql::class),
            $app->make(Analyzer::class),
            $app->make(ParallelRunner::class),
            $app['config']['db-snapshot.path'],
        ));

        $this->app->bind(SnapshotRepository::class, fn (Application $app): SnapshotRepository => new SnapshotRepository($app['config']['db-snapshot.path']));
    }
}
