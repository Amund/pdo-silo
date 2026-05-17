<?php

declare(strict_types=1);

namespace Silo;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 SimpleCache implementation backed by PDO or disk.
 *
 * Stores serialized values in the PREFIX_cache table (PDO mode)
 * or as individual files on disk (disk mode).
 */
class Cache implements CacheInterface
{
    private \PDO $pdo;
    private string $table;
    private ?string $cachePath;
    private string $prefix;

    /** @var array<string, \PDOStatement> Cached prepared statements. */
    private array $stmt = [];

    public function __construct(\PDO $pdo, string $namespace = 'silo_cache', ?string $cachePath = null)
    {
        $this->pdo = $pdo;
        $this->prefix = strtolower($namespace);
        $this->table = $this->prefix . '_cache';
        $this->cachePath = $cachePath;
    }

    /**
     * Create the cache table (PDO mode only).
     */
    public function create(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $tableSql = match ($driver) {
            'mysql' => 'CREATE TABLE IF NOT EXISTS `' . $this->table . '` ( `id` VARCHAR(255) NOT NULL, `resource` LONGTEXT NOT NULL, `ttl` INT(10) UNSIGNED DEFAULT NULL, PRIMARY KEY (`id`) ) COLLATE="utf8_unicode_ci" ENGINE=MyISAM',
            'pgsql' => 'CREATE TABLE IF NOT EXISTS "' . $this->table . '" ( "id" VARCHAR(255) NOT NULL, "resource" TEXT NOT NULL, "ttl" INTEGER DEFAULT NULL, PRIMARY KEY ("id") )',
            default => 'CREATE TABLE IF NOT EXISTS `' . $this->table . '` ( `id` VARCHAR(255) NOT NULL, `resource` LONGTEXT NOT NULL, `ttl` INT(10) DEFAULT NULL, PRIMARY KEY (`id`) )',
        };

        $this->pdo->exec($tableSql);
    }

    /**
     * Drop the cache table.
     */
    public function destroy(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->table);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->purgeExpired();

        $value = $this->fetchFromBackend($key);
        if ($value === null) {
            return $default;
        }

        $data = json_decode($value, true);
        if (!is_array($data) || !array_key_exists('value', $data)) {
            return $default;
        }

        // Check TTL
        if (isset($data['ttl']) && time() >= $data['ttl']) {
            $this->delete($key);
            return $default;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $expiresAt = $this->normalizeTtl($ttl);
        $data = json_encode([
            'value' => $value,
            'ttl' => $expiresAt,
        ]);

        if ($data === false) {
            return false;
        }

        if ($this->cachePath !== null) {
            return $this->writeDisk($key, $data, $expiresAt);
        }

        $stmt = $this->prepare('replace');
        return $stmt->execute([$key, $data, $expiresAt]);
    }

    public function delete(string $key): bool
    {
        if ($this->cachePath !== null) {
            $file = $this->diskPath($key);
            if (is_file($file)) {
                return unlink($file);
            }
            return true;
        }

        $stmt = $this->prepare('delete');
        return $stmt->execute([$key]);
    }

    public function clear(): bool
    {
        if ($this->cachePath !== null) {
            $dir = $this->cachePath . '/' . $this->prefix;
            if (is_dir($dir)) {
                $this->rmdirRecursive($dir);
            }
            return true;
        }

        $stmt = $this->prepare('clear');
        return $stmt->execute();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, $value, $ttl)) {
                return false;
            }
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            if (!$this->delete((string) $key)) {
                return false;
            }
        }
        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    /**
     * Normalize TTL to an absolute timestamp, or null for no expiration.
     */
    private function normalizeTtl(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl)->getTimestamp();
        }
        return time() + $ttl;
    }

    /**
     * Fetch raw value from the backend, checking expiration.
     */
    private function fetchFromBackend(string $key): ?string
    {
        if ($this->cachePath !== null) {
            return $this->readDisk($key);
        }

        $stmt = $this->prepare('select');
        $stmt->execute([$key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || !isset($row['resource'])) {
            return null;
        }

        // Check TTL stored in DB
        if (isset($row['ttl']) && (int) $row['ttl'] > 0 && time() >= (int) $row['ttl']) {
            $this->delete($key);
            return null;
        }

        return $row['resource'];
    }

    /**
     * Remove expired entries from the PDO backend.
     */
    private function purgeExpired(): void
    {
        // No-op for disk mode
        if ($this->cachePath !== null) {
            return;
        }
        $stmt = $this->prepare('purge');
        $stmt->execute([time()]);
    }

    private function prepare(string $stmtName): \PDOStatement
    {
        if (!array_key_exists($stmtName, $this->stmt)) {
            $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

            $sql = match ($stmtName) {
                'select' => 'SELECT resource, ttl FROM ' . $this->table . ' WHERE id=?',
                'replace' => match ($driver) {
                    'pgsql' => 'INSERT INTO ' . $this->table . ' ( id, resource, ttl ) VALUES ( ?, ?, ? ) ON CONFLICT ( id ) DO UPDATE SET resource = EXCLUDED.resource, ttl = EXCLUDED.ttl',
                    default => 'REPLACE INTO ' . $this->table . ' ( id, resource, ttl ) VALUES ( ?, ?, ? )',
                },
                'delete' => 'DELETE FROM ' . $this->table . ' WHERE id=?',
                'clear' => 'DELETE FROM ' . $this->table,
                'purge' => 'DELETE FROM ' . $this->table . ' WHERE ttl IS NOT NULL AND ttl <= ?',
                default => throw new \InvalidArgumentException('Unknown statement: ' . $stmtName),
            };

            $this->stmt[$stmtName] = $this->pdo->prepare($sql);
        }
        return $this->stmt[$stmtName];
    }

    private function diskPath(string $key): string
    {
        $hash = hash('sha1', $key);
        return implode('/', [
            $this->cachePath,
            $this->prefix,
            substr($hash, 0, 1),
            substr($hash, 1, 1),
            $hash,
        ]);
    }

    private function ensureDiskDir(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private function writeDisk(string $key, string $data, ?int $ttl): bool
    {
        $file = $this->diskPath($key);
        $this->ensureDiskDir($file);

        $payload = json_encode([
            'data' => $data,
            'ttl' => $ttl,
        ]);

        return $payload !== false && file_put_contents($file, $payload) !== false;
    }

    private function readDisk(string $key): ?string
    {
        $file = $this->diskPath($key);
        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return null;
        }

        // Check TTL
        if (isset($payload['ttl']) && time() >= $payload['ttl']) {
            unlink($file);
            return null;
        }

        return $payload['data'] ?? null;
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }
}
