<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Tests\FilamentTestCase;
use Murkrow\FilamentAi\Tests\PostgresTestCase;
use Murkrow\FilamentAi\Tests\TestCase;
use Murkrow\FilamentAi\Tests\WebTestCase;

uses(TestCase::class)->in('Feature');

// The pgvector suite talks to a real PostgreSQL and skips itself when none is
// reachable, so it lives in its own directory with its own base case.
uses(PostgresTestCase::class)->in('Pgvector');

// The panel suite boots a real Filament panel, which is heavier than the rest
// and only relevant when Filament is installed.
uses(FilamentTestCase::class)->in('Filament');

// The chat page is served over HTTP with a session and a logged-in user, none
// of which the Feature base case sets up -- and deliberately without Filament.
uses(WebTestCase::class)->in('Web');
