<?php

declare(strict_types=1);

namespace Silo\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use Silo\Silo;

class ResourceLinkTest extends TestCase
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
        $silo->cache = false;
        $silo->create();
        $this->silo = $silo;
        return $silo;
    }

    public function testLink(): void
    {
        $silo = $this->createSilo();
        $silo->setMeta(null, 'a');
        $silo->setMeta(null, 'b');
        $silo->setMeta(null, 'c');

        $this->assertTrue($silo->link(2, 1));
        $this->assertTrue($silo->link(3, 1));

        $this->assertSame(['a' => [2, 3]], $silo->linkTo(1));
        $this->assertSame(['a' => [1]], $silo->linkFrom(2));
    }

    public function testLinkWithAttribute(): void
    {
        $silo = $this->createSilo();
        $silo->setMeta(null, 'a');
        $silo->setMeta(null, 'b');
        $silo->setMeta(null, 'c');

        $this->assertTrue($silo->link(2, 1, 'tag'));
        $this->assertTrue($silo->link(3, 1, 'tag'));

        $this->assertSame(['tag' => [2, 3]], $silo->linkTo(1));
        $this->assertSame(['tag' => [1]], $silo->linkFrom(2));
    }

    public function testLinkToNonexistentResource(): void
    {
        $silo = $this->createSilo();
        $silo->setMeta(null, 'a');

        $this->assertFalse($silo->link(1, 999));
        $this->assertFalse($silo->link(999, 1));
    }

    public function testUnlinkSingle(): void
    {
        $silo = $this->createSilo();
        $silo->setMeta(null, 'a');
        $silo->setMeta(null, 'b');

        $silo->link(2, 1);
        $silo->unlink(2, 1);

        $this->assertSame([], $silo->linkTo(1));
    }

    public function testUnlinkFrom(): void
    {
        $silo = $this->createSilo();
        $silo->setMeta(null, 'a');
        $silo->setMeta(null, 'b');
        $silo->setMeta(null, 'c');

        $silo->link(1, 2);
        $silo->link(1, 3);
        $silo->unlink(1, null);

        $this->assertSame([], $silo->linkFrom(1));
    }

    public function testUnlinkTo(): void
    {
        $silo = $this->createSilo();
        $silo->setMeta(null, 'a');
        $silo->setMeta(null, 'b');
        $silo->setMeta(null, 'c');

        $silo->link(2, 1);
        $silo->link(3, 1);
        $silo->unlink(null, 1);

        $this->assertSame([], $silo->linkTo(1));
    }

    public function testUnlinkFromAndTo(): void
    {
        $silo = $this->createSilo();
        $silo->setMeta(null, 'a');
        $silo->setMeta(null, 'b');
        $silo->setMeta(null, 'c');

        $silo->link(2, 1);
        $silo->link(3, 2);
        $silo->unlink(2);

        $this->assertSame([], $silo->linkFrom(2));
        $this->assertSame([], $silo->linkTo(2));
    }
}
