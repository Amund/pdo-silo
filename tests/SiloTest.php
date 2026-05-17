<?php

declare(strict_types=1);

namespace Silo\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use Silo\Silo;

class SiloTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?Silo $silo = null;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo = null;
        }
        $this->silo = null;
    }

    private function createSilo(string $prefix = 'test'): Silo
    {
        $silo = new Silo($this->pdo, $prefix);
        $silo->create();
        $this->silo = $silo;
        return $silo;
    }

    private function createSiloWithoutCache(): Silo
    {
        $silo = $this->createSilo();
        $silo->cache = false;
        return $silo;
    }

    private function tablesNames(string $prefix = 'resource'): array
    {
        return [
            $prefix . '_meta',
            $prefix . '_attribute',
            $prefix . '_link',
            $prefix . '_cache',
        ];
    }

    private function listAllTables(PDO $pdo): array
    {
        return $pdo
            ->query('SELECT name FROM sqlite_master WHERE type="table"')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /* PDO */

    public function testPdo(): void
    {
        $this->assertInstanceOf(PDO::class, $this->pdo);
    }

    /* CREATE / DESTROY */

    public function testCreateDestroy(): void
    {
        $silo = new Silo($this->pdo);

        $silo->create();
        $this->assertSame($this->tablesNames(), $this->listAllTables($this->pdo));

        $silo->destroy();
        $this->assertSame([], $this->listAllTables($this->pdo));
    }

    public function testCreateDestroyWithPrefix(): void
    {
        $prefix = 'my_test_silo';
        $silo = new Silo($this->pdo, $prefix);

        $silo->create();
        $this->assertSame($this->tablesNames($prefix), $this->listAllTables($this->pdo));

        $silo->destroy();
        $this->assertSame([], $this->listAllTables($this->pdo));
    }

    /* GET / SET */

    public function testGetSet(): void
    {
        $silo = $this->createSiloWithoutCache();
        $persons = [
            ['name' => 'John Doe'],
            ['name' => 'Cynthia Doe'],
            ['name' => 'Régis Doe'],
        ];

        $i = 1;
        foreach ($persons as $person) {
            $this->assertSame($i, $silo->set('person', $person));
            $i++;
        }

        $silo->link(1, 2, 'husband');
        $silo->link(2, 1, 'wife');
        $silo->link(3, 1, 'son');
        $silo->link(3, 2, 'son');

        $this->assertSame([
            'id' => 1,
            'class' => 'person',
            'name' => 'John Doe',
        ], $silo->get(1));

        $this->assertSame([
            'id' => 1,
            'class' => 'person',
            'name' => 'John Doe',
            'links' => [
                'from' => [
                    'wife' => [2],
                    'son' => [3],
                ],
                'to' => [
                    'husband' => [2],
                ],
            ],
            'lists' => [
                'from' => [],
                'to' => [],
            ],
        ], $silo->get(1, true));

        $this->assertSame([
            'id' => 1,
            'class' => 'person',
            'name' => 'John Doe',
            'links' => [
                'from' => [
                    'wife' => [
                        ['id' => 2, 'class' => 'person', 'name' => 'Cynthia Doe'],
                    ],
                    'son' => [
                        ['id' => 3, 'class' => 'person', 'name' => 'Régis Doe'],
                    ],
                ],
                'to' => [
                    'husband' => [
                        ['id' => 2, 'class' => 'person', 'name' => 'Cynthia Doe'],
                    ],
                ],
            ],
            'lists' => [
                'from' => [],
                'to' => [],
            ],
        ], $silo->get(1, true, true));
    }

    public function testSetWithGetTrue(): void
    {
        $silo = $this->createSiloWithoutCache();

        $resource = $silo->set('person', ['name' => 'John'], true);
        $this->assertIsArray($resource);
        $this->assertSame(1, $resource['id']);
        $this->assertSame('person', $resource['class']);
        $this->assertSame('John', $resource['name']);
    }

    public function testGetNonexistentResource(): void
    {
        $silo = $this->createSiloWithoutCache();

        $this->assertNull($silo->get(999));
    }

    /* CACHE — PDO */

    public function testCacheEnabled(): void
    {
        $silo = $this->createSilo();
        $id = $silo->set('person', ['name' => 'John']);

        $resource = $silo->get($id);
        $this->assertSame('John', $resource['name']);
    }

    public function testCacheDisabledDoesNotCache(): void
    {
        $silo = $this->createSilo();
        $silo->cache = false;

        $id = $silo->set('person', ['name' => 'John']);

        $resource = $silo->get($id);
        $this->assertSame('John', $resource['name']);
    }

    public function testEmptyCache(): void
    {
        $silo = $this->createSilo();
        $id = $silo->set('person', ['name' => 'John']);

        $silo->get($id);

        $silo->emptyCache();

        $resource = $silo->get($id);
        $this->assertSame('John', $resource['name']);
    }

    public function testCacheInvalidatedOnWrite(): void
    {
        $silo = $this->createSilo();
        $id = $silo->set('person', ['name' => 'John']);

        $silo->get($id);

        $silo->setAttr($id, 'name', 'Jane');
        $resource = $silo->get($id);
        $this->assertSame('Jane', $resource['name']);
    }

    /* CACHE — Disk */

    public function testDiskCache(): void
    {
        $cacheDir = sys_get_temp_dir() . '/pdo-silo-test-' . bin2hex(random_bytes(8));

        try {
            $silo = new Silo($this->pdo, 'test', $cacheDir);
            $silo->create();
            $this->silo = $silo;

            $id = $silo->set('person', ['name' => 'John']);
            $resource = $silo->get($id);
            $this->assertSame('John', $resource['name']);

            $hash = hash('sha1', (string) $id);
            $cacheFile = $cacheDir . '/test/' . $hash[0] . '/' . $hash[1] . '/' . $hash;
            $this->assertFileExists($cacheFile);

            $cached = json_decode(file_get_contents($cacheFile), true);
            $this->assertSame('John', $cached['name']);
        } finally {
            $this->rmdirRecursive($cacheDir);
        }
    }

    public function testDiskCacheEmptyDirectory(): void
    {
        $cacheDir = sys_get_temp_dir() . '/pdo-silo-test-' . bin2hex(random_bytes(8));

        try {
            $silo = new Silo($this->pdo, 'test', $cacheDir);
            $silo->create();
            $this->silo = $silo;

            $id = $silo->set('person', ['name' => 'John']);

            $silo->get($id);

            $silo->emptyCache();

            $resource = $silo->get($id);
            $this->assertSame('John', $resource['name']);
        } finally {
            $this->rmdirRecursive($cacheDir);
        }
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
