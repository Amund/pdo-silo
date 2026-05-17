<?php

declare(strict_types=1);

namespace Silo\Tests;

use PHPUnit\Framework\TestCase;
use Silo\Cache;
use PDO;

class CacheTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
    }

    private function createCache(): Cache
    {
        $cache = new Cache($this->pdo, 'test_cache');
        $cache->create();
        return $cache;
    }

    public function testSetGet(): void
    {
        $cache = $this->createCache();

        $cache->set('key1', 'value1');
        $this->assertSame('value1', $cache->get('key1'));
    }

    public function testGetDefault(): void
    {
        $cache = $this->createCache();

        $this->assertNull($cache->get('nonexistent'));
        $this->assertSame('fallback', $cache->get('nonexistent', 'fallback'));
    }

    public function testHas(): void
    {
        $cache = $this->createCache();

        $this->assertFalse($cache->has('key1'));
        $cache->set('key1', 'value1');
        $this->assertTrue($cache->has('key1'));
    }

    public function testDelete(): void
    {
        $cache = $this->createCache();

        $cache->set('key1', 'value1');
        $this->assertTrue($cache->has('key1'));

        $cache->delete('key1');
        $this->assertFalse($cache->has('key1'));
    }

    public function testClear(): void
    {
        $cache = $this->createCache();

        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');
        $this->assertTrue($cache->has('key1'));
        $this->assertTrue($cache->has('key2'));

        $cache->clear();
        $this->assertFalse($cache->has('key1'));
        $this->assertFalse($cache->has('key2'));
    }

    public function testTtl(): void
    {
        $cache = $this->createCache();

        // TTL 0 means immediate expiry
        $cache->set('key1', 'value1', 0);
        $this->assertFalse($cache->has('key1'));
        $this->assertSame('fallback', $cache->get('key1', 'fallback'));

        // TTL null means no expiry
        $cache->set('key2', 'value2');
        $this->assertTrue($cache->has('key2'));
    }

    public function testMultiple(): void
    {
        $cache = $this->createCache();

        $result = $cache->setMultiple(['a' => 'A', 'b' => 'B']);
        $this->assertTrue($result);

        $this->assertSame('A', $cache->get('a'));
        $this->assertSame('B', $cache->get('b'));

        $values = $cache->getMultiple(['a', 'b', 'c']);
        $this->assertSame('A', $values['a']);
        $this->assertSame('B', $values['b']);
        $this->assertNull($values['c']);

        $cache->deleteMultiple(['a', 'b']);
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    public function testOverwrite(): void
    {
        $cache = $this->createCache();

        $cache->set('key1', 'first');
        $cache->set('key1', 'second');
        $this->assertSame('second', $cache->get('key1'));
    }

    public function testArrayValue(): void
    {
        $cache = $this->createCache();

        $data = ['foo' => 'bar', 'num' => 42];
        $cache->set('key1', $data);
        $this->assertSame($data, $cache->get('key1'));
    }

    public function testIntegerKey(): void
    {
        $cache = $this->createCache();

        $cache->set('42', 'value');
        $this->assertSame('value', $cache->get('42'));
    }

    public function testNullValue(): void
    {
        $cache = $this->createCache();

        $cache->set('key1', null);
        $this->assertTrue($cache->has('key1'));
        $this->assertNull($cache->get('key1'));
    }

    public function testDestroy(): void
    {
        $cache = $this->createCache();
        $cache->set('key1', 'value1');

        // Verify the table exists before destroy
        $tables = $this->pdo->query('SELECT name FROM sqlite_master WHERE type="table"')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('test_cache_cache', $tables);

        $cache->destroy();

        // Verify the table is gone
        $tables = $this->pdo->query('SELECT name FROM sqlite_master WHERE type="table"')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotContains('test_cache_cache', $tables);
    }

    public function testDiskCache(): void
    {
        $cacheDir = sys_get_temp_dir() . '/pdo-silo-cache-test-' . bin2hex(random_bytes(8));

        try {
            $cache = new Cache($this->pdo, 'test_disk', $cacheDir);
            $cache->create();

            $cache->set('key1', 'disk_value');
            $this->assertSame('disk_value', $cache->get('key1'));

            // Check file exists
            $hash = hash('sha1', 'key1');
            $path = $cacheDir . '/test_disk/' . $hash[0] . '/' . $hash[1] . '/' . $hash;
            $this->assertFileExists($path);

            $cache->clear();
            $this->assertFalse($cache->has('key1'));
        } finally {
            // Cleanup
            if (is_dir($cacheDir)) {
                $this->rmdirRecursive($cacheDir);
            }
        }
    }

    private function rmdirRecursive(string $dir): void
    {
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
