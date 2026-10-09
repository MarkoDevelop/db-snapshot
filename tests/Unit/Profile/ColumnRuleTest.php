<?php

use Overthink\DbSnapshot\Profile\AnonymizeStrategy;
use Overthink\DbSnapshot\Profile\ColumnRule;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;

it('reads anonymize rules in short and long form and writes them back the same way', function () {
    $rule = TableRule::fromArray([
        'mode' => 'full',
        'anonymize' => ['email' => 'email', 'name' => ['template' => 'Customer {hash}'], 'password' => ['fixed' => 'secret'], 'phone' => 'null'],
    ]);

    expect($rule->anonymize['email']->strategy)->toBe(AnonymizeStrategy::Email)
        ->and($rule->anonymize['name']->value)->toBe('Customer {hash}')
        ->and($rule->toArray()['anonymize'])->toBe([
            'email' => 'email',
            'name' => ['template' => 'Customer {hash}'],
            'password' => ['fixed' => 'secret'],
            'phone' => 'null',
        ]);
});

it('rejects anonymize rules that cannot work', function (Closure $build) {
    $build();
})->with([
    'template without placeholder' => [fn () => new ColumnRule(AnonymizeStrategy::Template, 'Customer')],
    'fixed without value' => [fn () => new ColumnRule(AnonymizeStrategy::Fixed)],
    'email with value' => [fn () => new ColumnRule(AnonymizeStrategy::Email, 'x')],
    'two keys' => [fn () => ColumnRule::fromJson(['template' => 'a {hash}', 'fixed' => 'b'])],
    'unknown strategy' => [fn () => ColumnRule::fromJson('scramble')],
    'schema-only table' => [fn () => new TableRule(TableMode::Schema, anonymize: ['email' => new ColumnRule(AnonymizeStrategy::Email)])],
    'column name injection' => [fn () => new TableRule(TableMode::Full, anonymize: ['email`; drop' => new ColumnRule(AnonymizeStrategy::Email)])],
])->throws(InvalidArgumentException::class);

it('mentions anonymized columns in the description', function () {
    $rule = new TableRule(TableMode::Recent, 'created_at', months: 3, anonymize: ['email' => new ColumnRule(AnonymizeStrategy::Email)]);

    expect($rule->describe())->toBe('last 3 months by created_at, anonymizes email');
});
