<?php

namespace Overthink\DbSnapshot\Support;

use Overthink\DbSnapshot\Analysis\ColumnInfo;
use Overthink\DbSnapshot\Profile\AnonymizeStrategy;
use Overthink\DbSnapshot\Profile\ColumnRule;

/**
 * Guesses from column names which columns hold personal data, and how to
 * replace them. Only a starting point: free text, JSON and logs can hold
 * personal data under any name.
 */
final class PersonalDataColumns
{
    private const PERSON_TABLES = '/(user|customer|contact|person|people|employee|member|client|account|recipient|buyer)/';

    public static function suggest(string $table, ColumnInfo $column): ?ColumnRule
    {
        if ($column->generated || $column->primaryKey) {
            return null;
        }

        $name = strtolower($column->name);
        $rule = match (true) {
            str_contains($name, 'email') => new ColumnRule(AnonymizeStrategy::Email),
            (bool) preg_match('/^(first_?name|last_?name|full_?name|surname|middle_?name|(customer|contact|billing|shipping|recipient|buyer)_name)$/', $name),
            $name === 'name' && preg_match(self::PERSON_TABLES, strtolower($table)) => new ColumnRule(AnonymizeStrategy::Template, 'Name {hash}'),
            (bool) preg_match('/(^|_)(phone|mobile|telephone|tel|gsm)(_|$)/', $name) => self::empty($column),
            // Before addresses, so ip_address is hashed rather than made a street.
            (bool) preg_match('/(^|_)(iban|ip|ip_address|tax_number|personal_id|emso|ssn|passport)(_|$)/', $name) => new ColumnRule(AnonymizeStrategy::Hash),
            (bool) preg_match('/(^|_)(address|street)(_|$)|^address_line/', $name) => new ColumnRule(AnonymizeStrategy::Template, 'Street {hash}'),
            in_array($name, ['remember_token', 'api_token', 'two_factor_secret', 'two_factor_recovery_codes'], true) => self::empty($column),
            default => null,
        };

        if ($rule === null || ($rule->strategy->needsTextColumn() && ! $column->isText())) {
            return $column->nullable && $rule !== null ? new ColumnRule(AnonymizeStrategy::Null) : null;
        }

        return $rule;
    }

    /**
     * NULL where allowed, an empty string otherwise.
     */
    private static function empty(ColumnInfo $column): ColumnRule
    {
        return new ColumnRule($column->nullable ? AnonymizeStrategy::Null : AnonymizeStrategy::Empty);
    }
}
