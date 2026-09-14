<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Resources\Pages\Page as ResourcePage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;

/**
 * Which resource and record a panel page is about, so the assistant can be
 * opened "about this".
 *
 * The context travels as a resource slug and a record key only. Whoever
 * receives it resolves the record again through the resource's own query and
 * policies: a key typed into the URL grants nothing.
 */
final class PageContext
{
    /**
     * @return array{0: class-string<\Filament\Resources\Resource>|null, 1: string|null}
     */
    public static function current(?Request $request = null): array
    {
        $route = ($request ?? request())->route();

        if (! $route instanceof Route) {
            return [null, null];
        }

        try {
            $page = $route->getControllerClass();
        } catch (Throwable) {
            return [null, null];
        }

        if ($page === null || ! is_subclass_of($page, ResourcePage::class)) {
            return [null, null];
        }

        $record = $route->parameter('record');

        $key = match (true) {
            $record instanceof Model => (string) $record->getKey(),
            is_scalar($record) && (string) $record !== '' => (string) $record,
            default => null,
        };

        return [$page::getResource(), $key];
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>|null  $resource
     * @return array<string, string>
     */
    public static function parameters(?string $resource, ?string $record): array
    {
        if ($resource === null) {
            return [];
        }

        return array_filter(
            ['resource' => $resource::getSlug(), 'record' => $record],
            static fn (?string $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @return class-string<\Filament\Resources\Resource>|null
     */
    public static function resourceForSlug(string $slug, ?Panel $panel = null): ?string
    {
        $panel ??= Filament::getCurrentOrDefaultPanel();

        foreach ($panel?->getResources() ?? [] as $resource) {
            try {
                if ($resource::getSlug() === $slug) {
                    return $resource;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
