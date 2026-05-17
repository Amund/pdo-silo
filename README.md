<a name="top"></a>
# PDO-Silo

A silo to store and link resources, via a simple API.

[![Latest Stable Version](https://poser.pugx.org/amund/pdo-silo/v/stable)](https://packagist.org/packages/amund/pdo-silo)

**License:** MIT  
**PHP:** 8.1+  
**Drivers:** MySQL, SQLite, PostgreSQL  

---

## Installation

```bash
composer require amund/pdo-silo
```

## Quick Overview

```php
use Silo\Silo;

// 1. Connect and create tables
$pdo = new \PDO('mysql:host=localhost;dbname=mydb;charset=utf8', 'login', 'password');
$silo = new Silo($pdo, 'test');
$silo->create();

// 2. Create resources (returns their ids)
$john  = $silo->set('person', ['firstname' => 'John', 'lastname' => 'Doe']);
$cynth = $silo->set('person', ['firstname' => 'Cynthia', 'lastname' => 'Doe']);
$regis = $silo->set('person', ['firstname' => 'Régis', 'lastname' => 'Doe']);
// $john = 1, $cynth = 2, $regis = 3

// 3. Read a resource
$silo->get($john);
// => ['id' => 1, 'class' => 'person', 'firstname' => 'John', 'lastname' => 'Doe']

// 4. Modify an attribute
$silo->setAttr($john, 'gender', 'M');

// 5. Link resources
$silo->link($john, $cynth, 'married_to');
$silo->link($regis, $john, 'son_of');

// 6. Read with links
$silo->get($john, links: true);
// => ['id' => 1, 'class' => 'person', ..., 'links' => [
//       'from' => ['married_to' => [2]],      // people linked TO John
//       'to'   => ['son_of' => [3]],           // people John links TO
//     ], 'lists' => [
//       'from' => [],                           // ordered lists John belongs to
//       'to'   => [],                           // ordered lists John owns
//     ]]

// 7. Search (accepts int values natively)
$silo->search(['where' => $silo->filter('age', '>=', 18)]);

$silo->search(['where' => $silo->filter('lastname', 'LIKE', '%Doe%')]);
// => ['total' => 3, 'results' => [1, 2, 3], 'duration' => '0.000123']

// 8. Ordered lists
$tagA = $silo->set('tag');
$tagB = $silo->set('tag');
$silo->setList($john, 'tags', [$tagA, $tagB]);
$silo->getList($john, 'tags'); // => [$tagA, $tagB]
```

## API

### Silo lifecycle

| Method | Description |
|--------|-------------|
| `__construct(\PDO $pdo, string $prefix = 'resource')` | Create a Silo instance. |
| `create(): void` | Create the 4 database tables (`PREFIX_meta`, `PREFIX_attribute`, `PREFIX_link`, `PREFIX_cache`) |
| `destroy(): void` | Drop all 4 tables |

### Resources

| Method | Description |
|--------|-------------|
| `set(string $class, array $attributes = [], bool $get = false): int\|array` | Create a resource. Returns its id (or full resource if `$get` is true). |
| `get(int $id, bool $links = false, bool $getLinks = false): ?array` | Retrieve a resource by id. Optionally include its links. |
| `getMeta(int $id): ?array` | Returns `['id', 'class']` or null. |
| `setMeta(?int $id, string $class): int` | Create (`$id = null`) or update a resource class. Returns the resource id. |
| `getAttr(int $id, string $attr): ?string` | Returns the attribute value, or null. |
| `setAttr(int $id, string $attr, mixed $value): mixed` | Set/update/delete an attribute. `null` and `''` delete the attribute. `'0'` and `0` are stored. |
| `getAttributes(int $id): array` | Returns all attributes as `['name' => 'value', ...]`. |
| `setAttributes(int $id, ?array $attributes): ?array` | Replace all attributes, or pass `null` to delete them all. |

### Links

| Method | Description |
|--------|-------------|
| `link(int $from, int $to, ?string $attribute = null): bool` | Create a link. Default attribute = target class name. |
| `unlink($from, $to): bool` | Remove a specific link, all links from, all links to, or all links for an id. |
| `linkFrom(int $id, bool $get = false): array` | Get child links, grouped by attribute. |
| `linkTo(int $id, bool $get = false): array` | Get parent links, grouped by attribute. |
| `getList(int $parent, string $attribute): array` | Get an ordered list of linked resources (by position). |
| `setList(int $parent, string $attribute, array $children): void` | Replace an ordered list. |
| `listFrom(int $id, bool $get = false): array` | Get list children, grouped by attribute. |
| `listTo(int $id, bool $get = false): array` | Get list parents, grouped by attribute. |

### Search

| Method | Description |
|--------|-------------|
| `search(array $arg = [], bool $get = false, bool $links = false, bool $getLinks = false): array` | Search resources. Returns `['total', 'results', 'duration']`. Supports `where`, `order`, `limit`, and `offset` keys. |
| `filter(string $field, string $operator, string\|int\|float\|array $value): string` | Build a WHERE clause fragment. Operators: `=`, `!=`, `<`, `>`, `<=`, `>=`, `LIKE`, `IN`. `int`/`float` values produce numeric comparisons. |
| `group(string $operator, string ...$filters): string` | Combine filters with `AND` or `OR`. |

### Cache

| Method | Description |
|--------|-------------|
| `emptyCache(): void` | Clear all cached resources. |

## Running Tests

```bash
composer test          # runs vendor/bin/phpunit
```

Tests use SQLite `:memory:`. Each test creates and destroys its own silo.

## Static Analysis

```bash
composer phpstan       # runs vendor/bin/phpstan analyse
composer check         # runs phpstan + tests
```

## Requirements

- PHP 8.1 or higher
- `ext-pdo` (required)
- `ext-pdo_mysql` (for MySQL), `ext-pdo_sqlite` (for SQLite), or `ext-pdo_pgsql` (for PostgreSQL)

## About

The idea behind PDO-Silo is to provide a minimal persistence layer for PHP projects — a nano ORM / DBAL. It is simple, small, fast, and fun to use.

Built by [Dimitri Avenel](https://github.com/Amund). Originally created in 2016.
