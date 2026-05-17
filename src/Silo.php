<?php

declare(strict_types=1);

namespace Silo;

use PDO;

/**
 * Facade that orchestrates Store, ResourceLink, Finder, caching, and lists.
 */
class Silo
{
    private PDO $pdo;
    private string $prefix;
    private Store $store;
    private ResourceLink $linker;
    private Finder $finder;
    private ResourceList $resourceList;

    /** @var array<string, \PDOStatement> */
    private array $stmt = [];
    private bool $_cache = true;

    /** @var array<string, string[]> DDL statements per driver. */
    private static array $schema = [
        'mysql' => [
            'CREATE TABLE IF NOT EXISTS `PREFIX_meta` ( `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT, `class` VARCHAR(255) NOT NULL, PRIMARY KEY (`id`), INDEX `class` (`class`) ) ENGINE=MyISAM',
            'CREATE TABLE IF NOT EXISTS `PREFIX_attribute` ( `id` INT(10) UNSIGNED NOT NULL, `attribute` VARCHAR(255) NOT NULL, `value` LONGTEXT NOT NULL, PRIMARY KEY (`id`, `attribute`), INDEX `attribute` (`attribute`), INDEX `id` (`id`) ) COLLATE="utf8_unicode_ci" ENGINE=MyISAM',
            'CREATE TABLE IF NOT EXISTS `PREFIX_link` ( `id_parent` INT(10) UNSIGNED NOT NULL, `id_child` INT(10) UNSIGNED NOT NULL, `attribute` VARCHAR(255) NOT NULL, `position` INT NOT NULL DEFAULT -1, PRIMARY KEY (`id_parent`, `id_child`, `attribute`, `position`), INDEX `id_parent` (`id_parent`), INDEX `id_child` (`id_child`), INDEX `attribute` (`attribute`) ) COLLATE="utf8_unicode_ci" ENGINE=MyISAM',
            'CREATE TABLE IF NOT EXISTS `PREFIX_cache` ( `id` INT(10) UNSIGNED NOT NULL, `resource` LONGTEXT NOT NULL, PRIMARY KEY (`id`) ) COLLATE="utf8_unicode_ci" ENGINE=MyISAM',
        ],
        'sqlite' => [
            'CREATE TABLE IF NOT EXISTS `PREFIX_meta` ( `id` INTEGER PRIMARY KEY, `class` VARCHAR(255) NOT NULL )',
            'CREATE TABLE IF NOT EXISTS `PREFIX_attribute` ( `id` INT(10) NOT NULL, `attribute` VARCHAR(255) NOT NULL, `value` LONGTEXT NOT NULL, PRIMARY KEY (`id`, `attribute`) )',
            'CREATE TABLE IF NOT EXISTS `PREFIX_link` ( `id_parent` INT(10) NOT NULL, `id_child` INT(10) NOT NULL, `attribute` VARCHAR(255) NOT NULL, `position` INT NOT NULL DEFAULT -1, PRIMARY KEY (`id_parent`, `id_child`, `attribute`, `position`) )',
            'CREATE TABLE IF NOT EXISTS `PREFIX_cache` ( `id` INT(10) NOT NULL, `resource` LONGTEXT NOT NULL, PRIMARY KEY (`id`) )',
            'CREATE INDEX `class` ON `PREFIX_meta` (`class` ASC)',
            'CREATE INDEX `attribute` ON `PREFIX_attribute` (`attribute` ASC)',
            'CREATE INDEX `id` ON `PREFIX_attribute` (`id` ASC)',
            'CREATE INDEX `id_parent` ON `PREFIX_link` (`id_parent` ASC)',
            'CREATE INDEX `id_child` ON `PREFIX_link` (`id_child` ASC)',
            'CREATE INDEX `linkattribute` ON `PREFIX_link` (`attribute` ASC)',
        ],
        'pgsql' => [
            'CREATE TABLE IF NOT EXISTS "PREFIX_meta" ( "id" SERIAL PRIMARY KEY, "class" VARCHAR(255) NOT NULL )',
            'CREATE TABLE IF NOT EXISTS "PREFIX_attribute" ( "id" INTEGER NOT NULL, "attribute" VARCHAR(255) NOT NULL, "value" TEXT NOT NULL, PRIMARY KEY ("id", "attribute") )',
            'CREATE TABLE IF NOT EXISTS "PREFIX_link" ( "id_parent" INTEGER NOT NULL, "id_child" INTEGER NOT NULL, "attribute" VARCHAR(255) NOT NULL, "position" INT NOT NULL DEFAULT -1, PRIMARY KEY ("id_parent", "id_child", "attribute", "position") )',
            'CREATE TABLE IF NOT EXISTS "PREFIX_cache" ( "id" INTEGER NOT NULL, "resource" TEXT NOT NULL, PRIMARY KEY ("id") )',
            'CREATE INDEX "class_idx" ON "PREFIX_meta" ("class")',
            'CREATE INDEX "attribute_idx" ON "PREFIX_attribute" ("attribute")',
            'CREATE INDEX "id_idx" ON "PREFIX_attribute" ("id")',
            'CREATE INDEX "id_parent_idx" ON "PREFIX_link" ("id_parent")',
            'CREATE INDEX "id_child_idx" ON "PREFIX_link" ("id_child")',
            'CREATE INDEX "linkattribute_idx" ON "PREFIX_link" ("attribute")',
        ],
    ];

    /**
     * @param \PDO   $pdo    Connected PDO instance (MySQL, SQLite, or PostgreSQL).
     * @param string $prefix Table prefix (default: 'resource').
     */
    public function __construct(PDO $pdo, string $prefix = 'resource')
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix !== '' ? strtolower($prefix) : 'resource';

        $this->store = new Store($pdo, $this->prefix);
        $this->linker = new ResourceLink($pdo, $this->prefix, $this->store);
        $this->finder = new Finder($pdo, $this->prefix);
        $this->resourceList = new ResourceList($pdo, $this->prefix);
    }

    public function create(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!isset(self::$schema[$driver])) {
            throw new Exception('Unsupported PDO driver');
        }
        foreach (self::$schema[$driver] as $sql) {
            $sql = str_replace('PREFIX', $this->prefix, $sql);
            $this->pdo->exec($sql);
        }
    }

    public function destroy(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->prefix . '_meta');
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->prefix . '_attribute');
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->prefix . '_link');
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->prefix . '_cache');
    }

    /** @param array<string, mixed> $attributes
     *  @return int|array<string, mixed> */
    public function set(string $class, array $attributes = [], bool $get = false): int|array
    {
        $id = $this->withoutCache(fn() => $this->store->setMeta(null, $class));
        if ($attributes !== []) {
            $this->withoutCache(fn() => $this->store->setAttributes($id, $attributes));
        }
        $resource = $this->get($id);
        $this->setCache($id, $resource);
        return $get === true ? $resource : $id;
    }

    /** @return ?array<string, mixed> */
    public function get(int $id, bool $links = false, bool $getLinks = false): ?array
    {
        if ($this->_cache) {
            $resource = $this->getCache($id);
            if ($resource !== false) {
                if ($links === true) {
                    $resolve = $getLinks ? fn(int $childId) => $this->get($childId) : null;
                    $resource['links']['from'] = $this->linker->to($id, $resolve);
                    $resource['links']['to'] = $this->linker->from($id, $resolve);
                    $resource['lists']['from'] = $this->resourceList->to($id, $resolve);
                    $resource['lists']['to'] = $this->resourceList->from($id, $resolve);
                }
                return $resource;
            }
        }

        $meta = $this->store->getMeta($id);
        if ($meta === null) {
            return null;
        }

        $attributes = $this->store->getAttributes($id);
        $resource = array_merge($meta, $attributes);

        if ($this->_cache) {
            $this->setCache($id, $resource);
        }

        if ($links === true) {
            $resolve = $getLinks ? fn(int $childId) => $this->get($childId) : null;
            $resource['links']['from'] = $this->linker->to($id, $resolve);
            $resource['links']['to'] = $this->linker->from($id, $resolve);
            $resource['lists']['from'] = $this->resourceList->to($id, $resolve);
            $resource['lists']['to'] = $this->resourceList->from($id, $resolve);
        }

        return $resource;
    }

    /** @return ?array{id: int, class: string} */
    public function getMeta(int $id): ?array
    {
        return $this->store->getMeta($id);
    }

    public function setMeta(?int $id, string $class): int
    {
        $result = $this->store->setMeta($id, $class);
        $this->withoutCache(fn() => $this->setCache($result, $this->get($result)));
        return $result;
    }

    public function getAttr(int $id, string $attr): ?string
    {
        return $this->store->getAttr($id, $attr);
    }

    public function setAttr(int $id, string $attr, mixed $value): mixed
    {
        $result = $this->store->setAttr($id, $attr, $value);
        $this->withoutCache(fn() => $this->setCache($id, $this->get($id)));
        return $result;
    }

    /** @return array<string, string> */
    public function getAttributes(int $id): array
    {
        return $this->store->getAttributes($id);
    }

    /** @param array<string, mixed>|null $attributes
     *  @return array<string, mixed>|null */
    public function setAttributes(int $id, ?array $attributes): ?array
    {
        $result = $this->store->setAttributes($id, $attributes);
        $this->withoutCache(fn() => $this->setCache($id, $this->get($id)));
        return $result;
    }

    /** @return int[] */
    public function getList(int $parent, string $attribute): array
    {
        return $this->resourceList->get($parent, $attribute);
    }

    /** @param int[] $children */
    public function setList(int $parent, string $attribute, array $children): void
    {
        $this->resourceList->set($parent, $attribute, $children);
    }

    public function link(int $from, int $to, ?string $attribute = null): bool
    {
        return $this->linker->link($from, $to, $attribute);
    }

    public function unlink(mixed $from = null, mixed $to = null): bool
    {
        $nbArgs = func_num_args();
        if ($nbArgs === 1) {
            $this->linker->unlink($from, null);
            $this->linker->unlink(null, $from);
            return true;
        }
        return $this->linker->unlink($from, $to);
    }

    /** @return array<string, list<int|array<string, mixed>>> */
    public function linkFrom(int $id, bool $get = false): array
    {
        return $this->linker->from($id, $get ? fn(int $childId) => $this->get($childId) : null);
    }

    /** @return array<string, list<int|array<string, mixed>>> */
    public function linkTo(int $id, bool $get = false): array
    {
        return $this->linker->to($id, $get ? fn(int $parentId) => $this->get($parentId) : null);
    }

    /** @return array<string, list<int|array<string, mixed>>> */
    public function listFrom(int $id, bool $get = false): array
    {
        return $this->resourceList->from($id, $get ? fn(int $childId) => $this->get($childId) : null);
    }

    /** @return array<string, list<int|array<string, mixed>>> */
    public function listTo(int $id, bool $get = false): array
    {
        return $this->resourceList->to($id, $get ? fn(int $parentId) => $this->get($parentId) : null);
    }

    /** @param array{where?: string, order?: string|string[], limit?: string|int, debug?: bool} $arg
     *  @return array{total: int, results: mixed[], duration: string}|string[]
     */
    public function search(array $arg = [], bool $get = false, bool $links = false, bool $getLinks = false): array
    {
        $result = $this->finder->search($arg);

        if ($get === true && isset($result['results']) && $result['results'] !== []) {
            $result['results'] = array_map(
                fn(int $id) => $this->get($id, $links, $getLinks),
                $result['results'],
            );
        }

        return $result;
    }

    public function filter(string $field, string $operator, mixed $value): string
    {
        return $this->finder->filter($field, $operator, $value);
    }

    public function group(): string
    {
        return $this->finder->group(...func_get_args());
    }

    public function emptyCache(): void
    {
        $stmt = $this->prepareCache('delete-all-cache');
        $stmt->execute();
    }

    /** @var array<string, string> Cache SQL statements. */
    private static array $cacheSql = [
        'select-cache'     => 'SELECT resource FROM PREFIX_cache WHERE id=?',
        'replace-cache'    => 'REPLACE INTO PREFIX_cache ( id, resource ) VALUES ( ?, ? )',
        'delete-cache'     => 'DELETE FROM PREFIX_cache WHERE id=?',
        'delete-all-cache' => 'DELETE FROM PREFIX_cache',
    ];

    private function prepareCache(string $stmtName): \PDOStatement
    {
        if (!isset($this->stmt[$stmtName])) {
            $sql = str_replace('PREFIX', $this->prefix, self::$cacheSql[$stmtName]);
            $this->stmt[$stmtName] = $this->pdo->prepare($sql);
        }
        return $this->stmt[$stmtName];
    }

    private function withoutCache(callable $fn): mixed
    {
        $previous = $this->_cache;
        $this->_cache = false;
        try {
            return $fn();
        } finally {
            $this->_cache = $previous;
        }
    }

    /** @return array<string, mixed>|false */
    private function getCache(int $id): array|false
    {
        $stmt = $this->prepareCache('select-cache');
        $stmt->execute([$id]);
        $resource = $stmt->fetch(PDO::FETCH_COLUMN);
        if (!$resource) {
            return false;
        }
        $decoded = json_decode($resource, true);
        return is_array($decoded) ? $decoded : false;
    }

    private function setCache(int $id, mixed $resource = null): void
    {
        if ($resource === null) {
            $stmt = $this->prepareCache('delete-cache');
            $stmt->execute([$id]);
        } else {
            $stmt = $this->prepareCache('replace-cache');
            $stmt->execute([$id, json_encode($resource)]);
        }
    }
}
