<?php

namespace Overthink\DbSnapshot\Profile;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class TableRule
{
    public function __construct(
        public readonly TableMode $mode,
        public readonly ?string $column = null,
        public readonly ?int $months = null,
        public readonly ?string $since = null,
        public readonly ?string $where = null,
    ) {
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
     * @param  array{mode: string, column?: string, months?: int, since?: string, where?: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            TableMode::from($data['mode']),
            $data['column'] ?? null,
            isset($data['months']) ? (int) $data['months'] : null,
            $data['since'] ?? null,
            $data['where'] ?? null,
        );
    }

    /**
     * @return array{mode: string, column?: string, months?: int, since?: string, where?: string}
     */
    public function toArray(): array
    {
        return array_filter([
            'mode' => $this->mode->value,
            'column' => $this->column,
            'months' => $this->months,
            'since' => $this->since,
            'where' => $this->where,
        ], fn (mixed $value): bool => $value !== null);
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
        return match ($this->mode) {
            TableMode::Recent => $this->months !== null
                ? "last {$this->months} months by {$this->column}"
                : "{$this->column} since {$this->since}",
            TableMode::Where => "where {$this->where}",
            default => $this->mode->value,
        };
    }
}
