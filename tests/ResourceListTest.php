<?php

declare(strict_types=1);

namespace Silo\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use Silo\Silo;

class ResourceListTest extends TestCase
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

    private function createSilo(): Silo
    {
        $silo = new Silo($this->pdo, 'test');
        $silo->cache = false;
        $silo->create();
        return $silo;
    }

    public function testGetEmptyList(): void
    {
        $silo = $this->createSilo();
        $id = $silo->set('post');

        $this->assertSame([], $silo->getList($id, 'tags'));
    }

    public function testSetAndGet(): void
    {
        $silo = $this->createSilo();
        $id = $silo->set('post');
        $tagA = $silo->set('tag');
        $tagB = $silo->set('tag');

        $silo->setList($id, 'tags', [$tagA, $tagB]);

        $this->assertSame([$tagA, $tagB], $silo->getList($id, 'tags'));
    }

    public function testSetReplacesPreviousList(): void
    {
        $silo = $this->createSilo();
        $id = $silo->set('post');
        $tagA = $silo->set('tag');
        $tagB = $silo->set('tag');
        $tagC = $silo->set('tag');

        $silo->setList($id, 'tags', [$tagA, $tagB]);
        $silo->setList($id, 'tags', [$tagB, $tagC]);

        $this->assertSame([$tagB, $tagC], $silo->getList($id, 'tags'));
    }

    public function testSetEmptyList(): void
    {
        $silo = $this->createSilo();
        $id = $silo->set('post');
        $tagA = $silo->set('tag');

        $silo->setList($id, 'tags', [$tagA]);
        $silo->setList($id, 'tags', []);

        $this->assertSame([], $silo->getList($id, 'tags'));
    }

    public function testListNotReturnedByFrom(): void
    {
        $silo = $this->createSilo();
        $post = $silo->set('post');
        $tagA = $silo->set('tag');

        $silo->setList($post, 'tags', [$tagA]);
        $this->assertSame([], $silo->linkFrom($post));
    }

    public function testLinkNotReturnedByGetList(): void
    {
        $silo = $this->createSilo();
        $post = $silo->set('post');
        $tag = $silo->set('tag');

        $silo->link($post, $tag, 'tags');
        $this->assertSame([], $silo->getList($post, 'tags'));
    }

    public function testMultipleAttributes(): void
    {
        $silo = $this->createSilo();
        $id = $silo->set('post');
        $a = $silo->set('tag');
        $b = $silo->set('tag');
        $c = $silo->set('tag');

        $silo->setList($id, 'authors', [$a]);
        $silo->setList($id, 'tags', [$b, $c]);

        $this->assertSame([$a], $silo->getList($id, 'authors'));
        $this->assertSame([$b, $c], $silo->getList($id, 'tags'));
    }

    public function testLinkAndListCoexist(): void
    {
        $silo = $this->createSilo();
        $post = $silo->set('post');
        $tagA = $silo->set('tag');
        $tagB = $silo->set('tag');

        $silo->link($post, $tagA, 'featured');
        $silo->setList($post, 'tags', [$tagB]);

        $this->assertSame(['featured' => [$tagA]], $silo->linkFrom($post));
        $this->assertSame([$tagB], $silo->getList($post, 'tags'));
    }

    public function testListFrom(): void
    {
        $silo = $this->createSilo();
        $post = $silo->set('post');
        $tagA = $silo->set('tag');
        $tagB = $silo->set('tag');

        $silo->setList($post, 'tags', [$tagA, $tagB]);

        $this->assertSame(['tags' => [$tagA, $tagB]], $silo->listFrom($post));
    }

    public function testListFromResolve(): void
    {
        $silo = $this->createSilo();
        $post = $silo->set('post');
        $tag = $silo->set('tag', ['label' => 'php']);

        $silo->setList($post, 'tags', [$tag]);

        $result = $silo->listFrom($post, true);
        $this->assertCount(1, $result['tags']);
        $this->assertSame($tag, $result['tags'][0]['id']);
        $this->assertSame('php', $result['tags'][0]['label']);
    }

    public function testListTo(): void
    {
        $silo = $this->createSilo();
        $postA = $silo->set('post');
        $postB = $silo->set('post');
        $tag = $silo->set('tag');

        $silo->setList($postA, 'tags', [$tag]);
        $silo->setList($postB, 'tags', [$tag]);

        $this->assertSame(['tags' => [$postA, $postB]], $silo->listTo($tag));
    }

    public function testListToResolve(): void
    {
        $silo = $this->createSilo();
        $post = $silo->set('post', ['title' => 'Hello']);
        $tag = $silo->set('tag');

        $silo->setList($post, 'tags', [$tag]);

        $result = $silo->listTo($tag, true);
        $this->assertCount(1, $result['tags']);
        $this->assertSame($post, $result['tags'][0]['id']);
        $this->assertSame('Hello', $result['tags'][0]['title']);
    }

    public function testGetWithLinksReturnsLists(): void
    {
        $silo = $this->createSilo();
        $post = $silo->set('post', ['title' => 'Hello']);
        $tag = $silo->set('tag', ['label' => 'php']);

        $silo->setList($post, 'tags', [$tag]);

        $resource = $silo->get($post, true);
        $this->assertArrayHasKey('lists', $resource);
        $this->assertSame(['tags' => [$tag]], $resource['lists']['to']);
    }
}
