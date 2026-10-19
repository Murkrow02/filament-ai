<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Assistant token API
|--------------------------------------------------------------------------
|
| The panel assistant for clients that are not a browser page -- a mobile
| app, a desktop client -- authenticated by whatever `filament-ai.api.middleware`
| says (Sanctum tokens, typically). Off unless FILAMENT_AI_API_ENABLED=true.
| The contract is documented in docs/api.md.
|
| Publish this file with
|
|     php artisan vendor:publish --tag=filament-ai-api-routes
|
| only to register the routes yourself; then set FILAMENT_AI_API_REGISTER=false
| so they are not registered twice, and keep the route names.
|
*/

use Illuminate\Support\Facades\Route;
use Murkrow\FilamentAi\Http\Controllers\Api\AssistantApiController;
use Murkrow\FilamentAi\Http\Controllers\Api\ConversationApiController;
use Murkrow\FilamentAi\Http\Controllers\TranscriptionController;

Route::get('config', [ConversationApiController::class, 'config'])->name('config');

Route::get('conversations', [ConversationApiController::class, 'index'])->name('conversations.index');
Route::get('conversations/{conversation}', [ConversationApiController::class, 'show'])->name('conversations.show');
Route::patch('conversations/{conversation}', [ConversationApiController::class, 'update'])->name('conversations.update');
Route::delete('conversations/{conversation}', [ConversationApiController::class, 'destroy'])->name('conversations.destroy');

Route::post('conversations/{conversation}/decisions', [AssistantApiController::class, 'decide'])->name('decide');

$ask = Route::post('ask', [AssistantApiController::class, 'ask'])->name('ask');

// Only answers while `filament-ai.chat.voice.engine` is `server`; a client that
// transcribes on the device never calls it.
$transcribe = Route::post('transcribe', TranscriptionController::class)->name('transcribe');

if (($throttle = config('filament-ai.api.throttle')) !== null && $throttle !== '') {
    $ask->middleware('throttle:'.$throttle);
    $transcribe->middleware('throttle:'.$throttle);
}
