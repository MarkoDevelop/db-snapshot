<?php

namespace Overthink\DbSnapshot\Profile;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class TableRule
{
    /**
     * @param  array<string, ColumnRule>  $anonymize  column => how to anonymize it
     */
    public function __construct(
        public readonly TableMode $mode,
        public readonly ?string $column = null,
        public readonly ?int $months = null,
        public readonly ?string $since = null,
        public readonly ?string $where = null,
        public readonly array $anonymize = [],
    ) {
        if ($anonymize !== [] && in_array($mode, [TableMode::Schema, TableMode::Skip], true)) {
            throw new InvalidArgumentException('"anonymize" only applies to rules that copy rows.');
        }

        foreach (array_keys($anonymize) as $anonymizedColumn) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', (string) $anonymizedColumn)) {
                throw new InvalidArgumentException("Invalid column name [{$anonymizedColumn}] in \"anonymize\".");
            }
        }

        if ($mode === TableMode::Recent) {
            if ($column === null || ! preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                throw new InvalidArgumentException('A "recent" rule needs a valid "column".');
            }

            if (($months === null) === ($since === null)) {
                throw new InvalidArgumentException('A "recent" rule needs either "months" or "since".');
            }

            if ($months !== null && $months < 1) {
                throw new InvalidArgumentException('"months" must be at least 1.');
            }

            if ($since !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $since)) {
                throw new InvalidArgumentException('"since" must be a Y-m-d date.');
            }
        }

        if ($mode === TableMode::Where && trim((string) $where) === '') {
            throw new InvalidArgumentException('A "where" rule needs a "where" clause.');
        }
    }

    /**
     * @param  array{mode: string, column?: string, months?: int, since?: string, where?: string, anonymize?: array<string, string|array<string, string>>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            TableMode::from($data['mode']),
            $data['column'] ?? null,
            isset($data['months']) ? (int) $data['months'] : null,
            $data['since'] ?? null,
            $data['where'] ?? null,
            array_map(ColumnRule::fromJson(...), $data['anonymize'] ?? []),
        );
    }

    /**
     * @return array{mode: string, column?: string, months?: int, since?: string, where?: string, anonymize?: array<string, string|array<string, string>>}
     */
    public function toArray(): array
    {
        $anonymize = $this->anonymize;
        ksort($anonymize);

        return array_filter([
            'mode' => $this->mode->value,
            'column' => $this->column,
            'months' => $this->months,
            'since' => $this->since,
            'where' => $this->where,
            'anonymize' => $anonymize === [] ? null : array_map(fn (ColumnRule $rule): string|array => $rule->toJson(), $anonymize),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The same rule with other anonymized columns.
     *
     * @param  array<string, ColumnRule>  $anonymize
     */
    public function withAnonymize(array $anonymize): self
    {
        return new self($this->mode, $this->column, $this->months, $this->since, $this->where, $anonymize);
    }

    /**
     * The cut-off date for a "recent" rule.
     */
    public function sinceDate(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        if ($this->mode !== TableMode::Recent) {
            return null;
        }

        if ($this->since !== null) {
            return CarbonImmutable::parse($this->since);
        }

        return ($now ?? CarbonImmutable::now())->subMonthsNoOverflow((int) $this->months)->startOfDay();
    }

    public function describe(): string
    {
        $description = match ($this->mode) {
            TableMode::Recent => $this->months !== null
                ? "last {$this->months} months by {$this->column}"
                : "{$this->column} since {$this->since}",
            TableMode::Where => "where {$this->where}",
            default => $this->mode->value,
        };

        return $this->anonymize === []
            ? $description
            : $description.', anonymizes '.implode(', ', array_keys($this->anonymize));
    }
}
