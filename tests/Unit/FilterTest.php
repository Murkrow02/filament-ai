<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Sources\Filter;
use Murkrow\FilamentAi\Sources\Filters\FilterValue;
use Murkrow\FilamentAi\Sources\FilterSet;

it('keys a set by filter name, not by column', function (): void {
    $set = new FilterSet(
        Filter::ids('ids', 'id'),
        Filter::range('id_range', 'id'),
        Filter::like('title'),
    );

    expect($set->names())->toBe(['ids', 'id_range', 'title'])
        ->and($set)->toHaveCount(3)
        ->and($set->get('id_range')?->column())->toBe('id')
        ->and($set->get('nope'))->toBeNull();
});

it('derives a label from the name when none is given', function (): void {
    expect(Filter::like('title')->label())->toBe('Title')
        ->and(Filter::like('title', label: 'Titolo contiene')->label())->toBe('Titolo contiene');
});

it('accepts the three shapes a filter value arrives in', function (): void {
    expect(FilterValue::list('1, 2 ,3'))->toBe(['1', '2', '3'])
        ->and(FilterValue::list([1, 2]))->toBe(['1', '2'])
        ->and(FilterValue::bounds('10-50'))->toBe(['10', '50'])
        ->and(FilterValue::bounds('10..50'))->toBe(['10', '50'])
        ->and(FilterValue::bounds(['from' => '10', 'to' => null]))->toBe(['10', null])
        ->and(FilterValue::bool('false'))->toBeFalse();
});

it('treats only null, empty string and empty array as no value', function (): void {
    expect(FilterValue::isBlank(null))->toBeTrue()
        ->and(FilterValue::isBlank(''))->toBeTrue()
        ->and(FilterValue::isBlank([]))->toBeTrue()
        ->and(FilterValue::isBlank(false))->toBeFalse()
        ->and(FilterValue::isBlank(0))->toBeFalse();
});
