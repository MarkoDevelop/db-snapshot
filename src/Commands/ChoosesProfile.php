<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Profile\Profile;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * One way to pick a profile in every command: the one given, or, when
 * interactive and there are several, a choice among the saved ones.
 */
trait ChoosesProfile
{
    private static string $newProfile = "\0new";

    /**
     * @param  bool  $allowNew  also offer to name a new profile
     */
    protected function chooseProfile(?string $given, bool $allowNew = false): string
    {
        if (filled($given)) {
            return (string) $given;
        }

        $directory = config('db-snapshot.profile_path');
        $names = Profile::names($directory);

        if (count($names) < 2 || ! $this->input->isInteractive()) {
            return $names === [] || in_array('default', $names, true) ? 'default' : $names[0];
        }

        $options = array_combine($names, $names);

        if ($allowNew) {
            $options[self::$newProfile] = 'New profile…';
        }

        $choice = (string) select(
            label: 'Which profile?',
            options: $options,
            default: in_array('default', $names, true) ? 'default' : $names[0],
        );

        return $choice !== self::$newProfile ? $choice : text(
            label: 'Name of the new profile',
            required: true,
            validate: fn (string $value): ?string => match (true) {
                ! preg_match('/^[A-Za-z0-9_-]+$/', $value) => 'Use letters, digits, - and _ only.',
                File::exists(Profile::path($directory, $value)) => "Profile [{$value}] already exists.",
                default => null,
            },
        );
    }
}
