<?php

declare(strict_types=1);

namespace Silo;

use PDO;

/**
 * Low-level CRUD operations for resources (meta + attributes).
 *
 * This class is used internally by Silo and has no cache awareness.
 */
class Store
{
    private PDO $pdo;
    private string $prefix;
    private string $driver;

    /** @var array<string, \PDOStatement> */
    private array $stmt = [];

    /** @var array<string, string> */
    private static array $sql = [
        'select-meta'      => 'SELECT * FROM PREFIX_meta WHERE id=?',
        'insert-meta'      => 'INSERT INTO PREFIX_meta ( class ) VALUES ( ? )',
        'update-meta'      => 'UPDATE PREFIX_meta SET class=? WHERE id=?',
        'delete-meta'      => 'DELETE FROM PREFIX_meta WHERE id=?',

        'select-attr'      => 'SELECT value FROM PREFIX_attribute WHERE id=? AND attribute =? LIMIT 1',
        'select-all-attr'  => 'SELECT attribute, value FROM PREFIX_attribute WHERE id=?',
        'replace-attr'     => 'REPLACE INTO PREFIX_attribute ( id, attribute, value ) VALUES ( ?, ?, ? )',
        'delete-attr'      => 'DELETE FROM PREFIX_attribute WHERE id=? AND attribute=?',
        'delete-all-attr'  => 'DELETE FROM PREFIX_attribute WHERE id=?',
    ];

    /** @var array<string, string> */
    private static array $pgsqlReplacements = [
        'replace-attr' => 'INSERT INTO PREFIX_attribute ( id, attribute, value ) VALUES ( ?, ?, ? ) ON CONFLICT ( id, attribute ) DO UPDATE SET value = EXCLUDED.value',
    ];

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
        $this->driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** @return ?array{id: int, class: string} */
    public function getMeta(int $id): ?array
    {
        $stmt = $this->prepare('select-meta');
        $stmt->execute([$id]);
        $meta = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($meta) ? $meta : null;
    }

    public function setMeta(?int $id, string $class): int
    {
        if ($id === null || $id === 0) {
            $this->prepare('insert-meta')->execute([$class]);
            return (int) $this->pdo->lastInsertId();
        }

        $this->prepare('update-meta')->execute([$class, $id]);
        return $id;
    }

    public function getAttr(int $id, string $attr): ?string
    {
        $stmt = $this->prepare('select-attr');
        $stmt->execute([$id, $attr]);
        $value = $stmt->fetch(PDO::FETCH_COLUMN);
        return $value === false ? null : $value;
    }

    public function setAttr(int $id, string $attr, mixed $value): mixed
    {
        if (!is_scalar($value) && $value !== null) {
            throw new Exception('Attribute value is not scalar');
        }

        $lowerAttr = strtolower($attr);
        if ($lowerAttr === 'id' || $lowerAttr === 'class' || $lowerAttr === 'links') {
            return null;
        }

        if (empty($value)) {
            $stmt = $this->prepare('delete-attr');
            $stmt->execute([$id, $attr]);
            return null;
        }

        $stmt = $this->prepare('replace-attr');
        $stmt->execute([$id, $attr, $value]);
        return $value;
    }

    /** @return array<string, string> */
    public function getAttributes(int $id): array
    {
        $stmt = $this->prepare('select-all-attr');
        $stmt->execute([$id]);
        $attributes = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $attributes[$row['attribute']] = $row['value'];
        }
        return $attributes;
    }

    /** @param array<string, mixed>|null $attributes
     *  @return array<string, mixed>|null */
    public function setAttributes(int $id, ?array $attributes): ?array
    {
        if ($attributes === null) {
            $stmt = $this->prepare('delete-all-attr');
            $stmt->execute([$id]);
            return null;
        }

        $this->setAttributes($id, null);
        foreach ($attributes as $k => $v) {
            $this->setAttr($id, $k, $v);
        }

        return $attributes;
    }

    private function prepare(string $stmtName): \PDOStatement
    {
        if (!isset($this->stmt[$stmtName])) {
            $sql = self::$sql[$stmtName];

            if ($this->driver === 'pgsql' && isset(self::$pgsqlReplacements[$stmtName])) {
                $sql = self::$pgsqlReplacements[$stmtName];
            }

            $sql = str_replace('PREFIX', $this->prefix, $sql);
            $this->stmt[$stmtName] = $this->pdo->prepare($sql);
        }
        return $this->stmt[$stmtName];
    }
}
