<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Assistant chat routes
|--------------------------------------------------------------------------
|
| Registered automatically by the service provider, so there is nothing to
| publish for the chat to work. Publish this file with
|
|     php artisan vendor:publish --tag=rag-chat-routes
|
| only when you want the registration in your own routes file -- to wrap it
| in different middleware, for instance. If you do, set RAG_CHAT_ENABLED=false
| so it is not registered twice, and register the routes with the same names:
| the page builds its own URLs from them.
|
| Everything except the two page routes is transport shared with the panel's
| chat page, which renders the same component.
|
*/

use Illuminate\Support\Facades\Route;
use Murkrow\FilamentAi\Http\Controllers\AssetController;
use Murkrow\FilamentAi\Http\Controllers\AssistantController;
use Murkrow\FilamentAi\Http\Controllers\ChatController;

// The standalone page. Both check rag.chat.enabled when the request arrives,
// not here: an application that flips the setting at runtime has already had
// its routes bound.
Route::get('/', [ChatController::class, 'index'])->name('index');
Route::get('c/{conversation}', [ChatController::class, 'show'])->name('show');

// The stylesheet and script live inside the package; see AssetController.
// They sit behind the same middleware as the page, which is the only thing
// that ever requests them.
Route::get('assets/{file}', AssetController::class)->name('asset');

Route::get('c/{conversation}/messages', [ChatController::class, 'messages'])->name('messages');
Route::patch('c/{conversation}', [ChatController::class, 'update'])->name('update');
Route::delete('c/{conversation}', [ChatController::class, 'destroy'])->name('destroy');

// Approving or rejecting what a paused turn is waiting on resumes it, and
// streams what the assistant does next.
Route::post('c/{conversation}/decisions', [AssistantController::class, 'decide'])->name('decide');

$ask = Route::post('ask', [AssistantController::class, 'ask'])->name('ask');

if (($throttle = config('rag.chat.throttle')) !== null && $throttle !== '') {
    $ask->middleware('throttle:'.$throttle);
}
