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

// creating a testing silo
$pdo = new \PDO('mysql:host=localhost;dbname=mydb;charset=utf8', 'login', 'password');
$silo = new Silo($pdo, 'test');
$silo->create();

// adding resources
$silo->set('person', ['firstname' => 'John', 'lastname' => 'Doe', 'gender' => 'M']); // => 1
$silo->set('person', ['firstname' => 'Cynthia', 'lastname' => 'Doe', 'gender' => 'F']); // => 2
$silo->set('person', ['firstname' => 'Régis', 'lastname' => 'Doe', 'gender' => 'M']); // => 3
$silo->set('address', ['street' => '5 Bedford St', 'city' => 'New York']); // => 4
$silo->set('animal', ['type' => 'fish', 'species' => 'Lutjanus sebae', 'color' => 'blue']); // => 5

// modifying resource
$silo->setAttr(4, 'zip', '10118');
$silo->setAttr(5, 'color', 'red');

// getting a resource by its id
$resource = $silo->get(1);
// => ['id' => 1, 'class' => 'person', 'firstname' => 'John', 'lastname' => 'Doe']

// adding some links
$silo->link(1, 2, 'husband');
$silo->link(2, 1, 'wife');
$silo->link(3, 1, 'son');
$silo->link(3, 2, 'son');
$silo->link(4, 1);
$silo->link(4, 2);
$silo->link(4, 3);
$silo->link(5, 3, 'pet');

// searching resources
$silo->search(['where' => $silo->filter('lastname', 'LIKE', 'doe')]);
// => [1, 2, 3]

$silo->search([
    'where' => $silo->group(
        'and',
        $silo->filter('class', '=', 'person'),
        $silo->filter('lastname', 'LIKE', 'doe'),
        $silo->filter('gender', '=', 'M')
    ),
]);
// => [1, 3]
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
| `setAttr(int $id, string $attr, mixed $value): mixed` | Set/update/delete an attribute. Empty values (`''`, `0`, `false`, `null`) delete the attribute. |
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
| `search(array $arg = [], bool $get = false, bool $links = false, bool $getLinks = false): array` | Search resources. Returns `['total', 'results', 'duration']`. Supports `where`, `order`, and `limit` keys. |
| `filter(string $field, string $operator, mixed $value): string` | Build a WHERE clause fragment. Operators: `=`, `!=`, `<`, `>`, `<=`, `>=`, `LIKE`, `IN`. |
| `group(): string` | Combine filters with `AND` or `OR`. |

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
