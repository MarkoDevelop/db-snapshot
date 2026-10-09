<?php

namespace Overthink\DbSnapshot\Profile;

use InvalidArgumentException;

/**
 * How one column is anonymized: a strategy, plus the text for "template" and "fixed".
 */
final class ColumnRule
{
    public function __construct(
        public readonly AnonymizeStrategy $strategy,
        public readonly ?string $value = null,
    ) {
        $needsValue = in_array($strategy, [AnonymizeStrategy::Template, AnonymizeStrategy::Fixed], true);

        if ($needsValue && $value === null) {
            throw new InvalidArgumentException("The \"{$strategy->value}\" strategy needs a value.");
        }

        if (! $needsValue && $value !== null) {
            throw new InvalidArgumentException("The \"{$strategy->value}\" strategy takes no value.");
        }

        if ($strategy === AnonymizeStrategy::Template && ! str_contains((string) $value, '{hash}') && ! str_contains((string) $value, '{id}')) {
            throw new InvalidArgumentException('A template needs {hash} or {id}, or every row gets the same value (use "fixed" for that).');
        }
    }

    /**
     * "email", "hash", "null", "empty", or {"template": "…"} / {"fixed": "…"}.
     *
     * @param  string|array<string, string>  $data
     */
    public static function fromJson(string|array $data): self
    {
        if (is_string($data)) {
            return new self(self::strategy($data));
        }

        if (count($data) !== 1) {
            throw new InvalidArgumentException('Use {"template": "…"} or {"fixed": "…"}.');
        }

        return new self(self::strategy((string) array_key_first($data)), (string) reset($data));
    }

    private static function strategy(string $name): AnonymizeStrategy
    {
        return AnonymizeStrategy::tryFrom($name) ?? throw new InvalidArgumentException(sprintf(
            'Unknown anonymize strategy [%s]; use one of: %s.',
            $name,
            implode(', ', array_map(fn (AnonymizeStrategy $strategy): string => $strategy->value, AnonymizeStrategy::cases())),
        ));
    }

    /**
     * @return string|array<string, string>
     */
    public function toJson(): string|array
    {
        return $this->value === null ? $this->strategy->value : [$this->strategy->value => $this->value];
    }

    public function usesId(): bool
    {
        return $this->strategy === AnonymizeStrategy::Template && str_contains((string) $this->value, '{id}');
    }

    public function describe(): string
    {
        return match ($this->strategy) {
            AnonymizeStrategy::Template => "\"{$this->value}\"",
            AnonymizeStrategy::Fixed => 'fixed value',
            default => $this->strategy->value,
        };
    }
}
