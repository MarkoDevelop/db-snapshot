<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Snapshot\Snapshot;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;
use Throwable;

use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * One way to pick a profile in every command: the one given, or, when
 * interactive, a choice among the saved ones (searchable when there are many).
 */
trait ChoosesProfile
{
    private static string $newProfile = "\0new";

    private static string $copyProfile = "\0copy";

    /**
     * For commands that only read a profile: asks when there are several.
     */
    protected function chooseProfile(?string $given): string
    {
        return $this->pickProfile($given)[0];
    }

    /**
     * @param  bool  $offerNew  also offer "New profile…" and "Copy a profile…"
     * @param  bool  $askWithOne  ask even when only one profile exists (for editing)
     * @return array{0: string, 1: ?string} the profile name, and the profile to copy it from
     */
    protected function pickProfile(?string $given, bool $offerNew = false, bool $askWithOne = false): array
    {
        if (filled($given)) {
            return [(string) $given, null];
        }

        $names = Profile::names(config('db-snapshot.profile_path'));

        if ($names === [] || ! $this->input->isInteractive() || (count($names) === 1 && ! $askWithOne)) {
            return [$names === [] || in_array('default', $names, true) ? 'default' : $names[0], null];
        }

        $options = $this->profileOptions($names);

        if ($offerNew) {
            $options[self::$newProfile] = 'New profile…';
            $options[self::$copyProfile] = 'Copy a profile…';
        }

        $choice = count($names) > 7
            ? (string) search(
                label: 'Which profile?',
                options: fn (string $value): array => array_filter(
                    $options,
                    fn (string $label): bool => $value === '' || str_contains(strtolower($label), strtolower($value)),
                ),
                placeholder: 'Type to filter',
                scroll: 10,
            )
            : (string) select(
                label: 'Which profile?',
                options: $options,
                default: in_array('default', $names, true) ? 'default' : $names[0],
                scroll: 10,
            );

        return match ($choice) {
            self::$newProfile => [$this->askNewProfileName(), null],
            self::$copyProfile => $this->askProfileCopy($names),
            default => [$choice, null],
        };
    }

    /**
     * "default · 4 rules · anonymizes 3 columns · pulled 2 hours ago" per profile.
     *
     * @param  list<string>  $names
     * @return array<string, string>
     */
    protected function profileOptions(array $names): array
    {
        $lastPulled = [];

        foreach ($this->laravel->make(SnapshotRepository::class)->all() as $snapshot) {
            $lastPulled[$snapshot->profile()] ??= $snapshot;
        }

        $options = [];

        foreach ($names as $name) {
            $options[$name] = implode(' · ', [$name, ...$this->profileDetails($name, $lastPulled[$name] ?? null)]);
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    protected function profileDetails(string $name, ?Snapshot $lastPulled): array
    {
        try {
            $profile = Profile::load(config('db-snapshot.profile_path'), $name);
        } catch (Throwable $exception) {
            return ['invalid: '.$exception->getMessage()];
        }

        $rules = count($profile->tables);
        $anonymized = array_sum(array_map(fn ($rule): int => count($rule->anonymize), $profile->tables));

        return array_values(array_filter([
            $rules === 1 ? '1 rule' : "{$rules} rules",
            $anonymized > 0 ? "anonymizes {$anonymized} ".($anonymized === 1 ? 'column' : 'columns') : null,
            $lastPulled !== null ? 'pulled '.$lastPulled->createdAt()->diffForHumans() : 'never pulled',
        ]));
    }

    private function askNewProfileName(string $default = ''): string
    {
        $directory = config('db-snapshot.profile_path');

        return text(
            label: 'Name of the new profile',
            default: $default,
            required: true,
            validate: fn (string $value): ?string => match (true) {
                ! preg_match('/^[A-Za-z0-9_-]+$/', $value) => 'Use letters, digits, - and _ only.',
                File::exists(Profile::path($directory, $value)) => "Profile [{$value}] already exists.",
                default => null,
            },
        );
    }

    /**
     * @param  list<string>  $names
     * @return array{0: string, 1: string}
     */
    private function askProfileCopy(array $names): array
    {
        $from = count($names) === 1 ? $names[0] : (string) select(
            label: 'Copy which profile?',
            options: array_combine($names, $names),
            default: in_array('default', $names, true) ? 'default' : $names[0],
            scroll: 10,
        );

        return [$this->askNewProfileName("{$from}-copy"), $from];
    }
}
