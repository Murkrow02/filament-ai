<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Chat\ToolLabels;
use Murkrow\FilamentAi\Agent\Metrics\Period;
use Murkrow\FilamentAi\Agent\Metrics\Periods;
use Murkrow\FilamentAi\Agent\PanelAssistant;
use Murkrow\FilamentAi\Tests\Fixtures\Agent\TestOrderMetric;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;
use Murkrow\FilamentAi\Tests\Fixtures\TestOrder;

/*
 * "How much did I sell this week", "who sold most in September", "compare
 * September with August": figures the list tools cannot give, since adding
 * up pages of records is exactly what a model gets wrong. A metric computes
 * them in SQL from a definition the application wrote.
 */

/**
 * @return array<string, mixed>
 */
function metric(array $arguments = []): array
{
    $result = (new TestOrderMetric)->handle(new Request($arguments));

    expect($result)->not->toStartWith('Error:');

    return json_decode($result, true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    Schema::create('test_orders', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('test_book_id')->nullable();
        $table->string('seller');
        $table->string('status');
        $table->unsignedInteger('amount');
        $table->unsignedInteger('quantity');
        $table->dateTime('ordered_at');
        $table->timestamps();
    });

    $this->travelTo(CarbonImmutable::parse('2026-10-22 15:00:00'));
    TestOrderMetric::$showAuthors = true;

    $linen = TestBook::create(['title' => 'Camicia lino', 'author' => 'Tessitura']);
    $jeans = TestBook::create(['title' => 'Jeans', 'author' => 'Denim & Co']);

    $order = fn (TestBook $book, string $seller, int $cents, int $pieces, string $at, string $status = 'completed') => TestOrder::create([
        'test_book_id' => $book->id, 'seller' => $seller, 'status' => $status,
        'amount' => $cents, 'quantity' => $pieces, 'ordered_at' => $at,
    ]);

    // August
    $order($linen, 'Lucia', 4900, 1, '2026-08-10 10:00:00');
    $order($jeans, 'Marco', 8000, 1, '2026-08-31 18:00:00');
    // September
    $order($linen, 'Lucia', 9800, 2, '2026-09-01 09:00:00');
    $order($jeans, 'Lucia', 8000, 1, '2026-09-15 12:00:00');
    $order($jeans, 'Marco', 16000, 2, '2026-09-30 19:30:00');
    $order($jeans, 'Marco', 99900, 9, '2026-09-20 12:00:00', 'cancelled');
    // October, this week (Monday 19th onwards)
    $order($linen, 'Marco', 4900, 1, '2026-10-02 11:00:00');
    $order($linen, 'Lucia', 4900, 1, '2026-10-20 11:00:00');
    $order($jeans, 'Lucia', 8000, 1, '2026-10-22 10:00:00');
});

it('totals a period, leaving out what the metric never counts', function (): void {
    $result = metric(['from' => '2026-09-01', 'to' => '2026-09-30']);

    expect($result['metric'])->toBe('Vendite')
        ->and($result['period'])->toBe(['from' => '2026-09-01', 'to' => '2026-09-30', 'label' => '2026-09-01 – 2026-09-30'])
        ->and($result['totals'])->toEqual(['orders' => 3, 'revenue' => 338, 'pieces' => 5])
        ->and($result['units'])->toBe(['revenue' => 'EUR']);
});

it('defaults to this month and knows the running presets', function (): void {
    expect(metric()['totals']['orders'])->toBe(3)
        ->and(metric(['period' => 'this_week'])['totals'])->toEqual(['orders' => 2, 'revenue' => 129, 'pieces' => 2])
        ->and(metric(['period' => 'today'])['totals']['orders'])->toBe(1)
        ->and(metric(['period' => 'last_month'])['period']['from'])->toBe('2026-09-01');
});

it('ranks the groups of a dimension, through a relationship too', function (): void {
    $bySeller = metric(['from' => '2026-09-01', 'to' => '2026-09-30', 'group_by' => 'seller']);
    $byBook = metric(['from' => '2026-09-01', 'to' => '2026-09-30', 'group_by' => 'book', 'sort_by' => 'pieces', 'limit' => 1]);

    expect($bySeller['groups'])->toEqual([
        ['seller' => 'Lucia', 'orders' => 2, 'revenue' => 178, 'pieces' => 3],
        ['seller' => 'Marco', 'orders' => 1, 'revenue' => 160.0, 'pieces' => 2.0],
    ])->and($byBook['groups'])->toEqual([
        ['book' => 'Jeans', 'orders' => 2, 'revenue' => 240.0, 'pieces' => 3.0],
    ]);
});

it('compares a whole month with the month before, and with last year', function (): void {
    $result = metric(['from' => '2026-09-01', 'to' => '2026-09-30', 'compare' => 'previous_period']);

    expect($result['compared_with']['period'])->toBe(['from' => '2026-08-01', 'to' => '2026-08-31', 'label' => 'previous period'])
        ->and($result['compared_with']['totals']['revenue'])->toEqual(129)
        ->and($result['compared_with']['change']['revenue'])->toEqual(['difference' => 209, 'percent' => 162])
        ->and($result['compared_with']['change']['orders'])->toEqual(['difference' => 1, 'percent' => 50]);

    $lastYear = metric(['from' => '2026-09-01', 'to' => '2026-09-30', 'compare' => 'previous_year']);

    expect($lastYear['compared_with']['period']['from'])->toBe('2025-09-01')
        ->and($lastYear['compared_with']['change']['revenue']['percent'])->toBeNull();
});

it('compares this month so far with the same days of last month', function (): void {
    $result = metric(['period' => 'this_month', 'compare' => 'previous_period']);

    expect($result['compared_with']['period'])->toMatchArray(['from' => '2026-09-01', 'to' => '2026-09-22'])
        ->and($result['compared_with']['totals']['orders'])->toBe(2);
});

it('returns a series by month', function (): void {
    $result = metric(['from' => '2026-08-01', 'to' => '2026-10-31', 'series' => 'month']);

    expect(array_column($result['series'], 'month'))->toBe(['2026-08', '2026-09', '2026-10'])
        ->and(array_column($result['series'], 'revenue'))->toEqual([129, 338, 178]);
});

it('narrows by its filters and the application\'s named periods', function (): void {
    expect(metric(['period' => 'saldi_estivi_2026'])['totals']['orders'])->toBe(2)
        ->and(metric(['from' => '2026-09-01', 'to' => '2026-09-30', 'seller' => 'Marco'])['totals']['orders'])->toBe(1)
        ->and((new TestOrderMetric)->handle(new Request(['seller' => 'Nobody'])))->toStartWith('Error: [Nobody] is not a valid seller');
});

it('refuses what it was not given instead of guessing', function (): void {
    $tool = new TestOrderMetric;

    expect($tool->handle(new Request(['group_by' => 'colour'])))->toStartWith('Error: cannot group by [colour]')
        ->and($tool->handle(new Request(['period' => 'next_decade'])))->toStartWith('Error: unknown period [next_decade]')
        ->and($tool->handle(new Request(['from' => '30/09/2026'])))->toBe('Error: "from" must be a date as YYYY-MM-DD.');
});

it('hides a dimension the current user may not use', function (): void {
    TestOrderMetric::$showAuthors = false;

    $schema = (new TestOrderMetric)->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory);

    expect($schema['group_by']->toArray()['enum'])->toBe(['seller', 'book'])
        ->and((new TestOrderMetric)->handle(new Request(['group_by' => 'author'])))->toStartWith('Error: cannot group by [author]');
});

it('describes itself and its arguments to the model', function (): void {
    $tool = new TestOrderMetric;
    $schema = $tool->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory);

    expect($tool->name())->toBe('metric_test_order')
        ->and($tool->description())->toStartWith('Vendite: orders: Ordini, revenue (EUR): Incasso, pieces: Pezzi. Completed orders only.')
        ->and(array_keys($schema))->toBe(['period', 'from', 'to', 'compare', 'group_by', 'sort_by', 'limit', 'series', 'seller'])
        ->and($schema['period']->toArray()['enum'])->toContain('this_month', 'saldi_estivi_2026');
});

it('is offered only when the application lists it', function (): void {
    $names = fn (): array => array_map(fn ($tool) => $tool->name(), iterator_to_array((new PanelAssistant)->tools(), false));

    expect($names())->not->toContain('metric_test_order');

    config()->set('filament-ai.agent.metrics', [TestOrderMetric::class]);

    expect($names())->toContain('metric_test_order')
        ->and(app(ToolLabels::class)->label('metric_test_order'))->toBe('Compute: Vendite');
});

it('shifts whole calendar units without overflowing', function (): void {
    $periods = new Periods;
    $today = CarbonImmutable::parse('2026-03-31');

    expect($periods->resolve('this_month', null, null, [], $today)->previous()->toArray())->toMatchArray(['from' => '2026-02-01', 'to' => '2026-02-28'])
        ->and(Period::days(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-08-31'), 'x')->previous()->toArray())->toMatchArray(['from' => '2026-05-01', 'to' => '2026-06-30'])
        ->and(Period::days(CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-19'), 'x')->previous()->toArray())->toMatchArray(['from' => '2026-08-31', 'to' => '2026-09-09']);
});
