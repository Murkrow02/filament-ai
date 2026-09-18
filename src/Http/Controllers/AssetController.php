<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the chat page's stylesheet and script from inside the package.
 *
 * The alternative -- publishing them into public/ -- puts a copy on disk that
 * nothing updates when the package does, and the failure mode is a page that
 * silently renders against last month's CSS. A route cannot go stale. The
 * files are fingerprinted in the URL and served immutable, so this costs one
 * request per deploy, not one per page view.
 */
class AssetController
{
    private const ALLOWED = [
        'filament-ai-chat.css' => 'text/css; charset=utf-8',
        'filament-ai-chat.js' => 'text/javascript; charset=utf-8',
        'alpine.js' => 'text/javascript; charset=utf-8',
    ];

    public function __invoke(string $file): Response
    {
        abort_unless(array_key_exists($file, self::ALLOWED), 404);

        $path = self::path($file);

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => self::ALLOWED[$file],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public static function path(string $file): string
    {
        return __DIR__.'/../../../resources/dist/'.$file;
    }

    /**
     * A short content fingerprint, so a deploy busts the year-long cache.
     */
    public static function url(string $file): string
    {
        $path = self::path($file);
        $version = is_file($path) ? substr(md5_file($path) ?: '', 0, 8) : 'dev';

        return route('filament-ai.chat.asset', ['file' => $file]).'?v='.$version;
    }
}
