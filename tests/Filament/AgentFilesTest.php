<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Chat\ConversationTranscript;
use Murkrow\FilamentAi\Agent\Chat\TurnStream;
use Murkrow\FilamentAi\Agent\Files\FileStore;
use Murkrow\FilamentAi\Tests\Fixtures\Agent\ExportBooks;
use Murkrow\FilamentAi\Tests\Fixtures\Agent\TestHostAssistant;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/*
 * "Send me September's report in Excel": a tool produces a file, the chat
 * shows a link that downloads it -- live and in a reloaded conversation --
 * and the link is the permission, signed and temporary.
 */

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('filament-ai.files.disk', 'local');
    config()->set('filament-ai.agent.assistant', TestHostAssistant::class);

    TestBook::create(['title' => 'Statuti']);
    TestBook::create(['title' => 'Gabelle']);
});

it('stores a file a tool produces and announces it in the result', function (): void {
    $result = json_decode((new ExportBooks)->handle(new Request([])), true);
    $file = $result['files'][0];

    expect($file)->toMatchArray(['name' => 'libri.csv', 'mime' => 'text/csv', 'size' => 22])
        ->and($file['url'])->toContain('signature=')
        ->and(Storage::disk('local')->get(app(FileStore::class)->path($file['id'], 'libri.csv')))->toBe("title\nStatuti\nGabelle\n");
});

it('downloads through the signed link only', function (): void {
    $file = json_decode((new ExportBooks)->handle(new Request([])), true)['files'][0];

    $this->get($file['url'])->assertOk()->assertDownload('libri.csv');
    $this->get(route('filament-ai.files.download', ['file' => $file['id']]))->assertForbidden();
    $this->get(URL::temporarySignedRoute('filament-ai.files.download', now()->subMinute(), ['file' => $file['id']]))->assertForbidden();
});

it('never lets a stored name escape the file\'s directory', function (): void {
    $file = app(FileStore::class)->put('x', '../../.env');

    expect($file['name'])->toBe('file-..-..-.env')
        ->and(app(FileStore::class)->put('x', 'meta.json')['name'])->toBe('file-meta.json');
});

it('streams a file event and keeps the file in the transcript', function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();
    config()->set('ai.conversations.generate_title', false);

    Ai::textProvider()->useTextGateway(new FakeTextGateway([
        new ToolCall('call_1', 'export_books', []),
        'Ecco il file.',
    ]));

    $events = app(TurnStream::class)->run((new TestHostAssistant)->forUser(auth()->user()), 'Esporta i libri', false);
    $files = [];

    foreach ($events as [$event, $data]) {
        if ($event === 'file') {
            $files[] = $data;
        }
    }

    $conversation = $events->getReturn()->conversationId;
    $reloaded = collect(app(ConversationTranscript::class)->messages($conversation))->firstWhere('role', 'assistant');

    expect($files)->toHaveCount(1)
        ->and($files[0]['name'])->toBe('libri.csv')
        ->and($reloaded['files'][0]['id'])->toBe($files[0]['id'])
        ->and($reloaded['files'][0]['url'])->toContain('signature=');
});

it('prunes files past their retention', function (): void {
    $store = app(FileStore::class);
    $old = $store->put('a', 'old.csv');

    $this->travel(8)->days();
    $new = $store->put('b', 'new.csv');

    $this->artisan('ai:files:prune')->assertSuccessful();

    expect($store->describe($old['id']))->toBeNull()
        ->and($store->describe($new['id']))->not->toBeNull();
});
