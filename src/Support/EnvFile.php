<?php

namespace Overthink\DbSnapshot\Support;

use Illuminate\Support\Facades\File;

/**
 * Sets keys in a .env file, replacing existing lines and appending new ones.
 */
final class EnvFile
{
    public function __construct(public readonly string $path) {}

    /**
     * @param  array<string, string|int|null>  $values
     */
    public function set(array $values): void
    {
        $contents = File::exists($this->path) ? File::get($this->path) : '';
        $appended = [];

        foreach ($values as $key => $value) {
            $line = $key.'='.self::format((string) $value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents)) {
                $contents = (string) preg_replace_callback($pattern, fn (): string => $line, $contents, 1);
            } else {
                $appended[] = $line;
            }
        }

        if ($appended !== []) {
            $contents = rtrim($contents, "\n").($contents === '' ? '' : "\n\n").implode("\n", $appended)."\n";
        }

        File::put($this->path, $contents);
    }

    /**
     * Quote values that dotenv would otherwise misread. Single quotes are
     * literal (no ${VAR} expansion), so they are used whenever possible.
     */
    public static function format(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.\/:@+-]+$/', $value)) {
            return $value;
        }

        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
