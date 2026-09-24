<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Agent\Resources\FormPipeline;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestArticleResource;
use Murkrow\FilamentAi\Tests\Fixtures\TestArticle;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

beforeEach(function (): void {
    TestArticleResource::$showSecret = false;
});

function pipeline(): FormPipeline
{
    return app(FormPipeline::class);
}

it('offers only the fields the form shows for the operation', function (): void {
    $names = fn (string $operation): array => array_map(
        fn ($field) => $field->getName(),
        pipeline()->offeredFields(TestArticleResource::class, $operation),
    );

    expect($names('create'))->toContain('title', 'status', 'locked', 'code', 'test_book_id')
        ->not->toContain('secret', 'password')
        ->and($names('edit'))->not->toContain('locked', 'secret');

    TestArticleResource::$showSecret = true;

    expect($names('create'))->toContain('secret');
});

it('creates through the form: validation, dehydration and hooks', function (): void {
    $outcome = pipeline()->create(TestArticleResource::class, [
        'title' => 'Statuti',
        'status' => 'draft',
        'secret' => 'smuggled',
        'code' => 'ab-12',
        'password' => 'hunter22',
        'password_confirmation' => 'hunter22',
        'unknown' => 'x',
    ]);

    $article = TestArticle::query()->sole();

    expect($outcome->passes())->toBeTrue()
        ->and($article->secret)->toBeNull()
        ->and($article->code)->toBe('AB-12')
        ->and($article->locked)->toBe('set by the resource')
        ->and($article->password)->toBeNull()
        ->and($outcome->ignored)->toContain('secret', 'password', 'password_confirmation', 'unknown');
});

it('refuses an option the select does not offer', function (): void {
    $outcome = pipeline()->create(TestArticleResource::class, ['title' => 'Statuti', 'status' => 'archived']);

    expect($outcome->passes())->toBeFalse()
        ->and($outcome->errors)->toHaveKey('status')
        ->and(TestArticle::query()->count())->toBe(0);
});

it('refuses a related record outside the select query', function (): void {
    $hidden = TestBook::query()->create(['title' => 'Nascosto', 'bad_ocr' => true]);
    $visible = TestBook::query()->create(['title' => 'Visibile']);

    expect(pipeline()->create(TestArticleResource::class, ['title' => 'A', 'status' => 'draft', 'test_book_id' => $hidden->id])->errors)
        ->toHaveKey('test_book_id')
        ->and(pipeline()->create(TestArticleResource::class, ['title' => 'B', 'status' => 'draft', 'test_book_id' => $visible->id])->passes())
        ->toBeTrue();
});

it('leaves a field disabled on edit untouched, and keeps what was not sent', function (): void {
    $article = TestArticle::query()->create(['title' => 'Old', 'status' => 'draft', 'locked' => 'keep', 'password' => 'hash']);

    $outcome = pipeline()->update(TestArticleResource::class, $article, ['title' => 'New', 'locked' => 'changed']);

    $article->refresh();

    expect($outcome->passes())->toBeTrue()
        ->and($article->title)->toBe('New')
        ->and($article->locked)->toBe('keep')
        ->and($article->password)->toBe('hash')
        ->and($outcome->ignored)->toContain('locked')
        ->and($outcome->changes)->toBe(['title' => ['before' => 'Old', 'after' => 'New']]);
});

it('previews a change without saving it', function (): void {
    $article = TestArticle::query()->create(['title' => 'Old', 'status' => 'draft']);

    $preview = pipeline()->preview(TestArticleResource::class, $article, ['status' => 'published']);

    expect($preview->passes())->toBeTrue()
        ->and($preview->changes)->toBe(['status' => ['before' => 'draft', 'after' => 'published']])
        ->and($article->refresh()->status)->toBe('draft');
});
