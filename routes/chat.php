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
use Murkrow\FilamentAi\Http\Controllers\AssetController;
use Murkrow\FilamentAi\Http\Controllers\ChatController;

Route::get('/', [ChatController::class, 'index'])->name('index');

// The stylesheet and script live inside the package; see AssetController.
// They sit behind the same middleware as the page, which is the only thing
// that ever requests them.
Route::get('assets/{file}', AssetController::class)->name('asset');

Route::post('conversations', [ChatController::class, 'store'])->name('store');

Route::get('c/{conversation}', [ChatController::class, 'show'])->name('show');
Route::get('c/{conversation}/messages', [ChatController::class, 'messages'])->name('messages');
Route::patch('c/{conversation}', [ChatController::class, 'update'])->name('update');
Route::delete('c/{conversation}', [ChatController::class, 'destroy'])->name('destroy');

Route::post('m/{query}/feedback', [ChatController::class, 'feedback'])->name('feedback');

$ask = Route::post('ask', AskController::class)->name('ask');

if (($throttle = config('rag.chat.throttle')) !== null && $throttle !== '') {
    $ask->middleware('throttle:'.$throttle);
}
