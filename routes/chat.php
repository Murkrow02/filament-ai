<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RAG chat routes
|--------------------------------------------------------------------------
|
| Registered automatically by the service provider, so there is nothing to
| publish for the page to work. Publish this file with
|
|     php artisan vendor:publish --tag=rag-chat-routes
|
| only when you want the registration in your own routes file -- to wrap it
| in different middleware, for instance. If you do, set RAG_CHAT_ENABLED=false
| so it is not registered twice, and register the routes with the same names:
| the page builds its own URLs from them.
|
*/

use Illuminate\Support\Facades\Route;
use Murkrow\FilamentAi\Http\Controllers\AskController;
use Murkrow\FilamentAi\Http\Controllers\AssistantController;
use Murkrow\FilamentAi\Http\Controllers\AssetController;
use Murkrow\FilamentAi\Http\Controllers\ChatController;

/*
 * The standalone page. Everything else in this file is transport, shared with
 * the panel page, so only these two check rag.chat.enabled -- and they check
 * it when the request arrives, not here: an application that flips the flag
 * at runtime has already had its routes bound.
 */
Route::get('/', [ChatController::class, 'index'])->name('index');
Route::get('c/{conversation}', [ChatController::class, 'show'])->name('show');

// The stylesheet and script live inside the package; see AssetController.
// They sit behind the same middleware as the page, which is the only thing
// that ever requests them.
Route::get('assets/{file}', AssetController::class)->name('asset');

Route::post('conversations', [ChatController::class, 'store'])->name('store');

Route::get('c/{conversation}/messages', [ChatController::class, 'messages'])->name('messages');
Route::patch('c/{conversation}', [ChatController::class, 'update'])->name('update');
Route::delete('c/{conversation}', [ChatController::class, 'destroy'])->name('destroy');

Route::post('m/{query}/feedback', [ChatController::class, 'feedback'])->name('feedback');

/*
 * Agent threads live in laravel/ai's own tables, not in rag_conversations:
 * they are the only place a turn paused for approval can be resumed from. So
 * they get their own two routes rather than a shared model binding.
 */
Route::get('a/{conversation}/messages', [AssistantController::class, 'messages'])->name('agent.messages');
Route::post('a/{conversation}/decisions', [AssistantController::class, 'decide'])->name('agent.decide');

$ask = Route::post('ask', AskController::class)->name('ask');

if (($throttle = config('rag.chat.throttle')) !== null && $throttle !== '') {
    $ask->middleware('throttle:'.$throttle);
}
