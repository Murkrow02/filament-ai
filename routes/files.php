<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Files the assistant's tools produce
|--------------------------------------------------------------------------
|
| One route: a temporary signed URL per file, minted whenever a conversation
| is read (see Agent/Files/FileStore). No session or token is needed -- the
| signature is the permission -- so the same link works from the web chat
| and from an app.
|
*/

use Illuminate\Support\Facades\Route;
use Murkrow\FilamentAi\Http\Controllers\FileDownloadController;

Route::get('{file}', FileDownloadController::class)
    ->middleware(['signed', 'throttle:60,1'])
    ->whereUuid('file')
    ->name('filament-ai.files.download');
