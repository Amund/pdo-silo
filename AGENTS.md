# PDO-Silo — Agent Guidelines

## Stack

- PHP 8.1+ with `declare(strict_types=1)`
- PDO (SQLite for tests, MySQL supported for production)
- PHPUnit 11 for testing
- Composer for autoloading (PSR-4)

## Namespace

| Class | FQCN | Path | Role |
|---|---|---|---|
| Facade | `Silo\Silo` | `src/Silo.php` | Public API, orchestrates services + cache |
| Store | `Silo\Store` | `src/Store.php` | CRUD: meta + attributes |
| Linker | `Silo\Linker` | `src/Linker.php` | Links between resources |
| Finder | `Silo\Finder` | `src/Finder.php` | Search + filter + group |
| Cache | `Silo\Cache` | `src/Cache.php` | PSR-16 SimpleCache adapter |
| Exception | `Silo\Exception` | `src/Exception.php` | Base exception |
| SiloTest | `Silo\Tests\SiloTest` | `tests/SiloTest.php` | Integration tests |
| CacheTest | `Silo\Tests\CacheTest` | `tests/CacheTest.php` | Cache unit tests |

## Conventions

- Strict typing enforced on all files
- One class per file
- Methods should have explicit return types
- Prefer `throw` over `trigger_error()`
- Prefer typed properties with promoted constructor properties
- Use PSR-12 coding style

## Architecture

Silo is split into 4 internal services orchestrated by the `Silo` facade:

```
Silo (facade)
 ├── Store   → getMeta/setMeta, getAttr/setAttr, getAttributes/setAttributes
 ├── Linker  → link, unlink, from, to
 ├── Finder  → search, filter, group
 └── Cache   → private: getCache, setCache, emptyCache
                public: Silo\Cache (PSR-16 adapter)
```

The facade handles cache update coordination after every write.

## Running Tests

```bash
composer test          # runs vendor/bin/phpunit
```

Tests use SQLite `:memory:`. Each test creates and destroys its own silo.

## Key Design Decisions

- **Services** (`Store`, `Linker`, `Finder`) have no cache awareness — the facade coordinates cache updates after writes.
- **Cache** can be PDO-based (stored in `PREFIX_cache` table), disk-based (partitioned by SHA1 hash), or used via the PSR-16 `Silo\Cache` adapter.
- **Search** uses raw SQL. For MySQL `SQL_CALC_FOUND_ROWS` is used for total counts; for SQLite and PostgreSQL a separate `SELECT COUNT(*)` subquery is used.
- **PostgreSQL** uses `INSERT … ON CONFLICT` (UPSERT) instead of `REPLACE`.
- **Linker** accepts an optional `$resolve` callable for `from()` / `to()` to resolve linked resource ids on-the-fly.
