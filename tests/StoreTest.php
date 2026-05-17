<?php

declare(strict_types=1);

namespace Silo\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use Silo\Silo;

class StoreTest extends TestCase
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
        $this->pdo = null;
        $this->silo = null;
    }

    private function createSilo(): Silo
    {
        $silo = new Silo($this->pdo, 'test');
        $silo->create();
        $this->silo = $silo;
        return $silo;
    }

    public function testMeta(): void
    {
        $silo = $this->createSilo();

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

    public function testAttr(): void
    {
        $silo = $this->createSilo();
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
        $silo = $this->createSilo();
        $id = $silo->setMeta(null, 'test');

        $silo->setAttr($id, 'score', '42');
        $this->assertSame('42', $silo->getAttr($id, 'score'));

        $silo->setAttr($id, 'score', '0');
        $this->assertNull($silo->getAttr($id, 'score'));
    }

    public function testAttributes(): void
    {
        $silo = $this->createSilo();
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
}
