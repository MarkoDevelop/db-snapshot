<?php

use Carbon\CarbonImmutable;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;

it('counts a recent rule back from now in whole days', function () {
    $rule = new TableRule(TableMode::Recent, 'created_at', months: 3);

    expect($rule->sinceDate(CarbonImmutable::parse('2026-05-31 15:20:00'))->format('Y-m-d H:i:s'))->toBe('2026-02-28 00:00:00');
});

it('starts a recent rule with a fixed date at that date', function () {
    $rule = new TableRule(TableMode::Recent, 'logged_at', since: '2025-01-15');

    expect($rule->sinceDate()->format('Y-m-d H:i:s'))->toBe('2025-01-15 00:00:00');
});

it('has no start date for other rules', function (TableMode $mode) {
    expect((new TableRule($mode))->sinceDate())->toBeNull();
})->with([TableMode::Full, TableMode::Schema, TableMode::Skip]);

it('rejects incomplete or unsafe rules', function (array $data) {
    TableRule::fromArray($data);
})->with([
    'recent without column' => [['mode' => 'recent', 'months' => 3]],
    'recent with injected column' => [['mode' => 'recent', 'column' => 'id`; DROP', 'months' => 3]],
    'recent without period' => [['mode' => 'recent', 'column' => 'created_at']],
    'recent with months and since' => [['mode' => 'recent', 'column' => 'created_at', 'months' => 3, 'since' => '2025-01-01']],
    'recent with zero months' => [['mode' => 'recent', 'column' => 'created_at', 'months' => 0]],
    'recent with a non-date since' => [['mode' => 'recent', 'column' => 'created_at', 'since' => "2025' OR 1=1"]],
    'where without clause' => [['mode' => 'where', 'where' => ' ']],
])->throws(InvalidArgumentException::class);

it('saves a follow rule without the default referenced column', function () {
    $rule = TableRule::fromArray(['mode' => 'follow', 'parent' => 'orders', 'column' => 'order_id']);

    expect($rule->toArray())->toBe(['mode' => 'follow', 'parent' => 'orders', 'column' => 'order_id'])
        ->and($rule->referencedColumn())->toBe('id')
        ->and($rule->describe())->toBe('rows of copied orders by order_id')
        ->and(TableRule::fromArray(['mode' => 'follow', 'parent' => 'orders', 'column' => 'order_number', 'references' => 'number'])->toArray())
        ->toBe(['mode' => 'follow', 'parent' => 'orders', 'column' => 'order_number', 'references' => 'number']);
});

it('rejects follow rules without a safe parent and column', function (array $data) {
    TableRule::fromArray(['mode' => 'follow', ...$data]);
})->with([
    'no parent' => [['column' => 'order_id']],
    'no column' => [['parent' => 'orders']],
    'injected parent' => [['parent' => 'orders WHERE 1', 'column' => 'order_id']],
    'injected references' => [['parent' => 'orders', 'column' => 'order_id', 'references' => 'id) OR (1']],
])->throws(InvalidArgumentException::class);
