<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Profile\Profile;

const STALE_QUESTION = 'The analysis of the remote database is from 2 months ago. Refresh it?';

function prepareStalePull(string $workspace): void
{
    Sleep::fake();
    Process::preventStrayProcesses();
    Process::fake([
        '*information_schema.TABLES*' => Process::result("orders\tBASE TABLE\t10\t1000\t0"),
        '*information_schema.COLUMNS*' => Process::result(''),
        '*mysqldump*' => Process::result(),
    ]);

    (new Profile('default'))->save($workspace.'/profiles');
    (new Analysis('production', now()->toImmutable()->subMonths(2), []))->save($workspace.'/analysis.json');
}

it('re-analyzes before pulling when the user picks analyze now', function () {
    prepareStalePull($this->workspace);

    $this->artisan('snapshot:pull')
        ->expectsChoice(STALE_QUESTION, 'analyze', ['analyze' => 'Analyze now', 'continue' => 'Continue with the current analysis'])
        ->assertSuccessful();

    expect(Analysis::load($this->workspace.'/analysis.json')->analyzedAt->isToday())->toBeTrue();
});

it('keeps the old analysis when the user continues', function () {
    prepareStalePull($this->workspace);

    $this->artisan('snapshot:pull')
        ->expectsChoice(STALE_QUESTION, 'continue', ['analyze' => 'Analyze now', 'continue' => 'Continue with the current analysis'])
        ->assertSuccessful();

    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'information_schema.COLUMNS'));
});

it('does not ask about a recent analysis', function () {
    prepareStalePull($this->workspace);
    (new Analysis('production', now()->toImmutable()->subDays(29), []))->save($this->workspace.'/analysis.json');

    $this->artisan('snapshot:pull')->assertSuccessful();

    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'information_schema.COLUMNS'));
});

it('only warns about a stale analysis when not interactive', function () {
    prepareStalePull($this->workspace);

    $this->artisan('snapshot:pull', ['--no-interaction' => true])
        ->expectsOutputToContain('run snapshot:analyze to refresh it')
        ->assertSuccessful();

    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'information_schema.COLUMNS'));
});

it('re-analyzes without asking when --analyze is given', function () {
    prepareStalePull($this->workspace);
    File::delete($this->workspace.'/analysis.json');

    $this->artisan('snapshot:pull', ['--analyze' => true])->assertSuccessful();

    expect(File::exists($this->workspace.'/analysis.json'))->toBeTrue();
});

it('offers to create a missing profile and stops when declined', function () {
    prepareStalePull($this->workspace);
    File::delete($this->workspace.'/profiles/default.json');
    (new Analysis('production', now()->toImmutable(), []))->save($this->workspace.'/analysis.json');

    $this->artisan('snapshot:pull')
        ->expectsConfirmation("Profile [default] doesn't exist yet. Create it now?", 'no')
        ->expectsOutputToContain('Run snapshot:configure default first')
        ->assertFailed();

    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'mysqldump'));
});
