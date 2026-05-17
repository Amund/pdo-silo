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
| ResourceLink | `Silo\ResourceLink` | `src/ResourceLink.php` | Links between resources |
| Finder | `Silo\Finder` | `src/Finder.php` | Search + filter + group |
| ResourceList | `Silo\ResourceList` | `src/ResourceList.php` | Ordered lists (position >= 0 in _link) |
| Exception | `Silo\Exception` | `src/Exception.php` | Base exception |
| SiloTest | `Silo\Tests\SiloTest` | `tests/SiloTest.php` | Integration tests (facade + cache) |
| StoreTest | `Silo\Tests\StoreTest` | `tests/StoreTest.php` | Store unit tests |
| ResourceLinkTest | `Silo\Tests\ResourceLinkTest` | `tests/ResourceLinkTest.php` | ResourceLink unit tests |
| FinderTest | `Silo\Tests\FinderTest` | `tests/FinderTest.php` | Finder unit tests |
| ResourceListTest | `Silo\Tests\ResourceListTest` | `tests/ResourceListTest.php` | ResourceList unit tests |

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
 ├── Store         → getMeta/setMeta, getAttr/setAttr, getAttributes/setAttributes
 ├── ResourceLink  → link, unlink, from, to
 ├── Finder        → search, filter, group
 ├── ResourceList  → getList, setList
 └── Cache         → private: getCache, setCache, emptyCache
```

The facade handles cache update coordination after every write.

## Running Tests

```bash
composer test          # runs vendor/bin/phpunit
```

Tests use SQLite `:memory:`. Each test creates and destroys its own silo.

## Key Design Decisions

- **Services** (`Store`, `ResourceLink`, `Finder`, `ResourceList`) have no cache awareness — the facade coordinates cache updates after writes.
- **Cache** is PDO-based, stored in the `PREFIX_cache` table. Always active — no toggle.
- **Search** uses raw SQL. For MySQL `SQL_CALC_FOUND_ROWS` is used for total counts; for SQLite and PostgreSQL a separate `SELECT COUNT(*)` subquery is used.
- **PostgreSQL** uses `INSERT … ON CONFLICT` (UPSERT) instead of `REPLACE`.
- **ResourceLink** accepts an optional `$resolve` callable for `from()` / `to()` to resolve linked resource ids on-the-fly.
- **Position** in the `_link` table defaults to `-1` (unordered `link()`). Lists use `position >= 0` and are ignored by `from()` / `to()`.
