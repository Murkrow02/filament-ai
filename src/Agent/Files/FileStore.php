<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

/**
 * Files a tool hands the user: a report, an export, a label sheet.
 *
 * A tool stores the bytes here and returns the descriptor in its result under
 * `files` (`AgentTool::file()` does both). The chat finds it there -- live, as
 * a `file` event, and in a reloaded conversation -- and shows a download link.
 * Links are temporary signed URLs, minted again whenever a conversation is
 * read, so an old chat still downloads while the file is kept.
 *
 * Files live on `filament-ai.files.disk` under `files.directory/<id>/`, next
 * to a small meta.json; `ai:files:prune` removes them after `files.keep_days`.
 */
final class FileStore
{
    /**
     * @return array{id: string, name: string, mime: string, size: int, url: string}
     */
    public function put(string $contents, string $name, ?string $mime = null): array
    {
        $id = (string) Str::uuid();
        $name = $this->safeName($name);
        $mime ??= $this->guessMime($name);

        $this->disk()->put($this->path($id, $name), $contents);
        $this->disk()->put($this->path($id, 'meta.json'), (string) json_encode([
            'name' => $name,
            'mime' => $mime,
            'size' => strlen($contents),
            'user' => auth()->id(),
            'created_at' => now()->toIso8601String(),
        ]));

        return $this->describe($id) ?? throw new \RuntimeException('The file could not be stored.');
    }

    /**
     * The descriptor of a stored file with a fresh link, or null once it is gone.
     *
     * @return array{id: string, name: string, mime: string, size: int, url: string}|null
     */
    public function describe(string $id): ?array
    {
        $meta = $this->meta($id);

        if ($meta === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $meta['name'],
            'mime' => $meta['mime'],
            'size' => $meta['size'],
            'url' => URL::temporarySignedRoute(
                'filament-ai.files.download',
                now()->addMinutes(max(1, (int) config('filament-ai.files.url_ttl', 1440))),
                ['file' => $id],
            ),
        ];
    }

    /**
     * @return array{name: string, mime: string, size: int}|null
     */
    public function meta(string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $raw = $this->disk()->get($this->path($id, 'meta.json'));
        $meta = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($meta) || ! is_string($meta['name'] ?? null) || ! $this->disk()->exists($this->path($id, $meta['name']))) {
            return null;
        }

        return ['name' => $meta['name'], 'mime' => (string) ($meta['mime'] ?? 'application/octet-stream'), 'size' => (int) ($meta['size'] ?? 0)];
    }

    public function path(string $id, string $name): string
    {
        return trim((string) config('filament-ai.files.directory', 'filament-ai/files'), '/').'/'.$id.'/'.$name;
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('filament-ai.files.disk') ?: config('filesystems.default'));
    }

    /**
     * The files a tool result announces, with fresh links; files no longer
     * kept are left out.
     *
     * @return list<array{id: string, name: string, mime: string, size: int, url: string}>
     */
    public function fromToolResult(mixed $result): array
    {
        if (! is_string($result) || ! str_starts_with(ltrim($result), '{') || ! str_contains($result, '"files"')) {
            return [];
        }

        $decoded = json_decode($result, true);
        $files = [];

        foreach (is_array($decoded) && is_array($decoded['files'] ?? null) ? $decoded['files'] : [] as $file) {
            $described = is_array($file) && is_string($file['id'] ?? null) ? $this->describe($file['id']) : null;

            if ($described !== null) {
                $files[] = $described;
            }
        }

        return $files;
    }

    /**
     * Removes files older than `files.keep_days`. Returns how many.
     */
    public function prune(): int
    {
        $keep = now()->subDays(max(0, (int) config('filament-ai.files.keep_days', 7)));
        $root = trim((string) config('filament-ai.files.directory', 'filament-ai/files'), '/');
        $removed = 0;

        foreach ($this->disk()->directories($root) as $directory) {
            $raw = $this->disk()->get($directory.'/meta.json');
            $meta = is_string($raw) ? json_decode($raw, true) : null;
            $created = is_array($meta) && is_string($meta['created_at'] ?? null) ? strtotime($meta['created_at']) : false;

            if ($created === false || $created < $keep->getTimestamp()) {
                $this->disk()->deleteDirectory($directory);
                $removed++;
            }
        }

        return $removed;
    }

    private function safeName(string $name): string
    {
        $name = trim(str_replace(['/', '\\', "\0"], '-', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';

        return $name === '' || $name === 'meta.json' || $name[0] === '.' ? 'file-'.$name : Str::limit($name, 150, '');
    }

    private function guessMime(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? 'application/octet-stream';
    }
}
