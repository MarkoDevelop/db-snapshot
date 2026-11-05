<?php

namespace Overthink\DbSnapshot\Commands;

use Overthink\DbSnapshot\Snapshot\Snapshot;
use RuntimeException;

use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;

/**
 * Pickers for saved add-ons: a list with a few, searchable with more than 7.
 */
trait PicksAddons
{
    use Formats;

    /**
     * @param  list<Snapshot>  $addons
     */
    protected function pickAddon(array $addons, string $label): Snapshot
    {
        $options = $this->addonOptions($addons);

        $name = count($options) > 7
            ? (string) search(label: $label, options: fn (string $value): array => $this->filterAddonOptions($options, $value), placeholder: 'Type to filter', scroll: 10)
            : (string) select(label: $label, options: $options, scroll: 10);

        return $this->addonNamed($addons, $name);
    }

    /**
     * @param  list<Snapshot>  $addons
     * @param  list<string>  $default  directory names to preselect
     * @return list<Snapshot>
     */
    protected function pickAddons(array $addons, string $label, array $default = []): array
    {
        $options = $this->addonOptions($addons);
        $hint = 'Their tables replace the snapshot\'s. Space selects, Enter continues.';

        $names = count($options) > 7
            ? multisearch(label: $label, options: fn (string $value): array => $this->filterAddonOptions($options, $value), placeholder: 'Type to filter', scroll: 10, hint: $hint)
            : multiselect(label: $label, options: $options, default: $default, scroll: 10, hint: $hint);

        // multisearch has no default, so add-ons named up front are kept here.
        $names = array_unique([...array_map(strval(...), $names), ...(count($options) > 7 ? $default : [])]);

        return array_map(fn (string $name): Snapshot => $this->addonNamed($addons, $name), array_values($names));
    }

    protected function isValidAddonName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]+$/', $name);
    }

    /**
     * True when the option was given without a value, meaning "let me pick".
     */
    protected function wantsAddonPicker(string $option): bool
    {
        $value = $this->option($option);

        return $this->input->hasParameterOption("--{$option}")
            && (is_array($value) ? in_array(null, $value, true) || $value === [] : blank($value));
    }

    /**
     * @param  list<Snapshot>  $addons
     * @return array<string, string>
     */
    private function addonOptions(array $addons): array
    {
        if ($addons === []) {
            throw new RuntimeException('There are no add-ons yet. Save one with snapshot:refresh-table --save.');
        }

        $options = [];

        foreach ($addons as $addon) {
            $options[$addon->name()] = $this->addonLabel($addon);
        }

        return $options;
    }

    /**
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    private function filterAddonOptions(array $options, string $value): array
    {
        return array_filter($options, fn (string $label): bool => $value === '' || str_contains(strtolower($label), strtolower($value)));
    }

    /**
     * @param  list<Snapshot>  $addons
     */
    private function addonNamed(array $addons, string $name): Snapshot
    {
        return current(array_filter($addons, fn (Snapshot $addon): bool => $addon->name() === $name))
            ?: throw new RuntimeException("No add-on [{$name}].");
    }
}
