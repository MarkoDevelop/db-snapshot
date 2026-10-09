<?php

use Overthink\DbSnapshot\Analysis\ColumnInfo;
use Overthink\DbSnapshot\Support\PersonalDataColumns;

function textColumn(string $name, bool $nullable = true): ColumnInfo
{
    return new ColumnInfo($name, 'varchar', ColumnInfo::TEXT, 191, nullable: $nullable);
}

it('suggests a replacement from the column name', function (string $table, ColumnInfo $column, ?array $expected) {
    expect(PersonalDataColumns::suggest($table, $column)?->toJson())->toBe($expected === null ? null : (count($expected) === 1 && array_is_list($expected) ? $expected[0] : $expected));
})->with([
    'email' => ['orders', textColumn('customer_email'), ['email']],
    'person name' => ['orders', textColumn('customer_name'), [['template' => 'Name {hash}']]],
    'name of a user' => ['users', textColumn('name'), [['template' => 'Name {hash}']]],
    'name of a product' => ['products', textColumn('name'), null],
    'nullable phone' => ['users', textColumn('phone'), ['null']],
    'required phone' => ['users', textColumn('phone', nullable: false), ['empty']],
    'address' => ['orders', textColumn('shipping_address'), [['template' => 'Street {hash}']]],
    'ip' => ['sessions', textColumn('ip_address'), ['hash']],
    'non-text email' => ['users', new ColumnInfo('email_verified', 'tinyint', ColumnInfo::OTHER), ['null']],
    'order number' => ['orders', textColumn('number'), null],
]);
