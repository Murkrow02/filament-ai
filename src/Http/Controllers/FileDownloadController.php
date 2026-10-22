<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Controllers;

use Murkrow\FilamentAi\Agent\Files\FileStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a file a tool produced. The signed URL is the permission: it was
 * handed only to the conversation's owner, and it expires.
 */
final class FileDownloadController
{
    public function __invoke(FileStore $files, string $file): StreamedResponse
    {
        $meta = $files->meta($file) ?? abort(404);

        return $files->disk()->download($files->path($file, $meta['name']), $meta['name'], [
            'Content-Type' => $meta['mime'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
