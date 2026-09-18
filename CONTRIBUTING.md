# Contributing

Thanks for taking the time to contribute.

## Development

```bash
composer install
vendor/bin/pest
```

The `Pgvector` suite needs a real PostgreSQL with the `vector` extension. It
skips itself when none is reachable; to run it, start one and point the suite at
it:

```bash
docker run -d --name fai-test-pg \
  -e POSTGRES_USER=rag -e POSTGRES_PASSWORD=rag -e POSTGRES_DB=rag_test \
  -p 55432:5432 pgvector/pgvector:pg17

FILAMENT_AI_TEST_PG_HOST=127.0.0.1 FILAMENT_AI_TEST_PG_PORT=55432 vendor/bin/pest --testsuite=Pgvector
```

## Pull requests

- Add or update tests for any behaviour change.
- Keep the four invariants in `CLAUDE.md` intact.
- Update `CHANGELOG.md` under `## [Unreleased]`.
- CI must be green.

## Releases

Patch releases are tagged automatically on every push to `main`. For a minor or
major release, push the tag by hand:

```bash
git tag -a v1.1.0 -m "Release v1.1.0" && git push origin v1.1.0
```

Packagist publishes from the tag.
