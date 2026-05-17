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

    /* META */

    public function testMeta(): void
    {
        $silo = $this->createSiloWithoutCache();

        $id = $silo->setMeta(null, 'test1');
        $this->assertSame(1, $id);

        $id = $silo->setMeta(null, 'test2');
        $this->assertSame(2, $id);

        $id = $silo->setMeta(null, 'test3');
        $this->assertSame(3, $id);

        $id = $silo->setMeta(null, 'test4');
        $this->assertSame(4, $id);

        $meta = $silo->getMeta(5);
        $this->assertNull($meta);

        $id = $silo->setMeta(5, 'test5');
        $this->assertSame(5, $id);

        $meta = $silo->getMeta(1);
        $this->assertSame(['id' => 1, 'class' => 'test1'], $meta);

        $silo->setMeta(1, 'A brand new class of my own');
        $meta = $silo->getMeta(1);
        $this->assertSame(['id' => 1, 'class' => 'A brand new class of my own'], $meta);
    }

    /* ATTR */

    public function testAttr(): void
    {
        $silo = $this->createSiloWithoutCache();
        $id = $silo->setMeta(null, 'test');

        $silo->setAttr($id, 'attr1', 'val1');
        $silo->setAttr($id, 'attr2', 'val2');
        $silo->setAttr($id, 'attr3', 'val3');
        $silo->setAttr($id, 'attr4', 'val4');

        $value = $silo->getAttr($id, 'attr1');
        $this->assertSame('val1', $value);

        $silo->setAttr($id, 'attr1', 'modified');
        $this->assertSame('modified', $silo->getAttr($id, 'attr1'));

        $value = $silo->getAttr($id, 'attr5');
        $this->assertNull($value);

        $silo->setAttr($id, 'attr1', '');
        $silo->setAttr($id, 'attr2', 0);
        $silo->setAttr($id, 'attr3', false);
        $silo->setAttr($id, 'attr4', null);
        $this->assertNull($silo->getAttr($id, 'attr1'));
        $this->assertNull($silo->getAttr($id, 'attr2'));
        $this->assertNull($silo->getAttr($id, 'attr3'));
        $this->assertNull($silo->getAttr($id, 'attr4'));

        $reserved = ['id', 'class', 'links', 'ID', 'Id'];
        foreach ($reserved as $attr) {
            $value = $silo->setAttr($id, $attr, 'value');
            $this->assertNull($value);
        }
    }

    public function testAttrZeroIsFalsyAndDeletes(): void
    {
        $silo = $this->createSiloWithoutCache();
        $id = $silo->setMeta(null, 'test');

        $silo->setAttr($id, 'score', '42');
        $this->assertSame('42', $silo->getAttr($id, 'score'));

        // '0' is empty() = true, so it deletes the attribute (by design)
        $silo->setAttr($id, 'score', '0');
        $this->assertNull($silo->getAttr($id, 'score'));
    }

    /* ATTRIBUTES (plural) */

    public function testAttributes(): void
    {
        $silo = $this->createSiloWithoutCache();
        $id = $silo->setMeta(null, 'test');
        $attr = [
            'attr1' => 'value1',
            'attr2' => 'value2',
        ];

        $this->assertSame([], $silo->getAttributes($id));

        $this->assertSame($attr, $silo->setAttributes($id, $attr));

        $this->assertSame($attr, $silo->getAttributes($id));

        $this->assertNull($silo->setAttributes($id, null));
    }

    /* LINKS */

    public function testLink(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->setMeta(null, 'a');
        $silo->setMeta(null, 'b');
        $silo->setMeta(null, 'c');

        $this->assertTrue($silo->link(2, 1));
        $this->assertTrue($silo->link(3, 1));

        $this->assertSame(['a' => [2, 3]], $silo->to(1));
        $this->assertSame(['a' => [1]], $silo->from(2));
    }

    public function testLinkWithAttribute(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->setMeta(null, 'a');
        $silo->setMeta(null, 'b');
        $silo->setMeta(null, 'c');

        $this->assertTrue($silo->link(2, 1, 'tag'));
        $this->assertTrue($silo->link(3, 1, 'tag'));

        $this->assertSame(['tag' => [2, 3]], $silo->to(1));
        $this->assertSame(['tag' => [1]], $silo->from(2));
    }

    public function testLinkToNonexistentResource(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->setMeta(null, 'a');

        $this->assertFalse($silo->link(1, 999));
        $this->assertFalse($silo->link(999, 1));
    }

    /* UNLINK */

    public function testUnlinkSingle(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->setMeta(null, 'a');
        $silo->setMeta(null, 'b');

        $silo->link(2, 1);
        $silo->unlink(2, 1);

        $this->assertSame([], $silo->to(1));
    }

    public function testUnlinkFrom(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->setMeta(null, 'a');
        $silo->setMeta(null, 'b');
        $silo->setMeta(null, 'c');

        $silo->link(1, 2);
        $silo->link(1, 3);
        $silo->unlink(1, null);

        $this->assertSame([], $silo->from(1));
    }

    public function testUnlinkTo(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->setMeta(null, 'a');
        $silo->setMeta(null, 'b');
        $silo->setMeta(null, 'c');

        $silo->link(2, 1);
        $silo->link(3, 1);
        $silo->unlink(null, 1);

        $this->assertSame([], $silo->to(1));
    }

    public function testUnlinkFromAndTo(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->setMeta(null, 'a');
        $silo->setMeta(null, 'b');
        $silo->setMeta(null, 'c');

        $silo->link(2, 1);
        $silo->link(3, 2);
        $silo->unlink(2);

        $this->assertSame([], $silo->from(2));
        $this->assertSame([], $silo->to(2));
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

    /* SEARCH */

    public function testSearchByClass(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->set('person', ['name' => 'John']);
        $silo->set('person', ['name' => 'Jane']);
        $silo->set('animal', ['species' => 'cat']);

        $result = $silo->search([
            'where' => $silo->filter('class', '=', 'person'),
        ]);
        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['results']);
        $this->assertContains(1, $result['results']);
        $this->assertContains(2, $result['results']);
    }

    public function testSearchByAttribute(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->set('person', ['name' => 'John Doe']);
        $silo->set('person', ['name' => 'Jane Doe']);
        $silo->set('person', ['name' => 'Bob Smith']);

        $result = $silo->search([
            'where' => $silo->filter('name', 'LIKE', '%Doe%'),
        ]);
        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['results']);
    }

    public function testSearchWithGroup(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->set('person', ['name' => 'John', 'gender' => 'M']);
        $silo->set('person', ['name' => 'Jane', 'gender' => 'F']);
        $silo->set('person', ['name' => 'Bob', 'gender' => 'M']);

        $result = $silo->search([
            'where' => $silo->group(
                'and',
                $silo->filter('class', '=', 'person'),
                $silo->filter('gender', '=', 'M')
            ),
        ]);
        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['results']);
        $this->assertContains(1, $result['results']);
        $this->assertContains(3, $result['results']);
    }

    public function testSearchOrdered(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->set('person', ['name' => 'Charlie']);
        $silo->set('person', ['name' => 'Alice']);
        $silo->set('person', ['name' => 'Bob']);

        // Insertion order: Charlie(1), Alice(2), Bob(3).
        // Sorted by name ASC: Alice(2), Bob(3), Charlie(1).
        $result = $silo->search([
            'where' => $silo->filter('class', '=', 'person'),
            'order' => 'name ASC',
        ]);
        $this->assertSame([2, 3, 1], $result['results']);
    }

    public function testSearchOrderedDesc(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->set('person', ['name' => 'Alice']);
        $silo->set('person', ['name' => 'Bob']);
        $silo->set('person', ['name' => 'Charlie']);

        $result = $silo->search([
            'where' => $silo->filter('class', '=', 'person'),
            'order' => 'name DESC',
        ]);
        $this->assertSame([3, 2, 1], $result['results']);
    }

    public function testSearchWithLimit(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->set('person', ['name' => 'John']);
        $silo->set('person', ['name' => 'Jane']);
        $silo->set('person', ['name' => 'Bob']);

        $result = $silo->search([
            'where' => $silo->filter('class', '=', 'person'),
            'limit' => 2,
        ]);
        $this->assertSame(3, $result['total']);
        $this->assertCount(2, $result['results']);
    }

    public function testSearchNoResults(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->set('person', ['name' => 'John']);

        $result = $silo->search([
            'where' => $silo->filter('class', '=', 'nonexistent'),
        ]);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['results']);
    }

    public function testSearchWithGet(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->set('person', ['name' => 'John']);

        $result = $silo->search([
            'where' => $silo->filter('class', '=', 'person'),
        ], true);
        $this->assertCount(1, $result['results']);
        $this->assertSame(1, $result['results'][0]['id']);
        $this->assertSame('person', $result['results'][0]['class']);
        $this->assertSame('John', $result['results'][0]['name']);
    }

    public function testSearchWithLinks(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->set('person', ['name' => 'John']);
        $silo->set('person', ['name' => 'Jane']);
        $silo->link(1, 2, 'knows');

        $result = $silo->search([
            'where' => $silo->filter('class', '=', 'person'),
        ], true, true, false);

        $this->assertCount(2, $result['results']);
        $this->assertArrayHasKey('links', $result['results'][0]);
        $this->assertArrayHasKey('links', $result['results'][1]);
    }

    public function testSearchDebug(): void
    {
        $silo = $this->createSiloWithoutCache();

        $result = $silo->search([
            'where' => $silo->filter('class', '=', 'person'),
            'debug' => true,
        ]);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('SELECT', $result[0]);
    }

    /* FILTER */

    public function testFilterOperators(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->set('person', ['age' => '25', 'name' => 'John']);
        $silo->set('person', ['age' => '30', 'name' => 'Jane']);
        $silo->set('person', ['age' => '35', 'name' => 'Bob']);

        $result = $silo->search(['where' => $silo->filter('age', '=', '30')]);
        $this->assertSame(1, $result['total']);

        $result = $silo->search(['where' => $silo->filter('age', '!=', '30')]);
        $this->assertSame(2, $result['total']);

        $result = $silo->search(['where' => $silo->filter('age', '<', '30')]);
        $this->assertSame(1, $result['total']);

        $result = $silo->search(['where' => $silo->filter('age', '>', '30')]);
        $this->assertSame(1, $result['total']);

        $result = $silo->search(['where' => $silo->filter('age', '<=', '30')]);
        $this->assertSame(2, $result['total']);

        $result = $silo->search(['where' => $silo->filter('age', '>=', '30')]);
        $this->assertSame(2, $result['total']);

        $result = $silo->search(['where' => $silo->filter('age', 'IN', '25,35')]);
        $this->assertSame(2, $result['total']);
    }

    public function testFilterInvalidOperator(): void
    {
        $silo = $this->createSiloWithoutCache();

        $this->expectException(\Silo\Exception::class);
        $silo->filter('age', 'INVALID', 'value');
    }

    public function testFilterNumericComparison(): void
    {
        $silo = $this->createSiloWithoutCache();
        // Choisis pour que le tri string donne des résultats différents du tri numérique.
        // String : '9' > '30' (vrai, '9' > '3'), '100' > '30' (faux, '1' < '3')
        // Num    : 9 > 30 (faux), 100 > 30 (vrai)
        $silo->set('product', ['price' => '9']);    // id=1
        $silo->set('product', ['price' => '30']);   // id=2
        $silo->set('product', ['price' => '100']);  // id=3

        $result = $silo->search(['where' => $silo->filter('price', '>', '30')]);
        $this->assertSame([3], $result['results']);

        $result = $silo->search(['where' => $silo->filter('price', '<', '30')]);
        $this->assertSame([1], $result['results']);

        $result = $silo->search(['where' => $silo->filter('price', '>=', '30')]);
        $this->assertSame([2, 3], $result['results']);

        $result = $silo->search(['where' => $silo->filter('price', '<=', '30')]);
        $this->assertSame([1, 2], $result['results']);
    }

    public function testFilterNonStringValue(): void
    {
        $silo = $this->createSiloWithoutCache();

        $this->expectException(\Silo\Exception::class);
        $silo->filter('age', '=', 42);
    }

    /* GROUP */

    public function testGroupOr(): void
    {
        $silo = $this->createSiloWithoutCache();
        $silo->set('person', ['name' => 'John']);
        $silo->set('animal', ['name' => 'Rex']);

        $result = $silo->search([
            'where' => $silo->group(
                'or',
                $silo->filter('class', '=', 'person'),
                $silo->filter('name', '=', 'Rex')
            ),
        ]);
        $this->assertSame(2, $result['total']);
    }

    public function testGroupInvalidOperator(): void
    {
        $silo = $this->createSiloWithoutCache();

        $this->expectException(\Silo\Exception::class);
        $silo->group('xor', $silo->filter('class', '=', 'person'));
    }

    public function testGroupNotEnoughFilters(): void
    {
        $silo = $this->createSiloWithoutCache();

        $this->expectException(\Silo\Exception::class);
        $silo->group('and');
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
