<?php

declare(strict_types=1);

namespace Silo\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use Silo\Silo;

class FinderTest extends TestCase
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

    /* SEARCH */

    public function testSearchByClass(): void
    {
        $silo = $this->createSilo();
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
        $silo = $this->createSilo();
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
        $silo = $this->createSilo();
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
        $silo = $this->createSilo();
        $silo->set('person', ['name' => 'Charlie']);
        $silo->set('person', ['name' => 'Alice']);
        $silo->set('person', ['name' => 'Bob']);

        $result = $silo->search([
            'where' => $silo->filter('class', '=', 'person'),
            'order' => 'name ASC',
        ]);
        $this->assertSame([2, 3, 1], $result['results']);
    }

    public function testSearchOrderedDesc(): void
    {
        $silo = $this->createSilo();
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
        $silo = $this->createSilo();
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
        $silo = $this->createSilo();
        $silo->set('person', ['name' => 'John']);

        $result = $silo->search([
            'where' => $silo->filter('class', '=', 'nonexistent'),
        ]);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['results']);
    }

    public function testSearchWithGet(): void
    {
        $silo = $this->createSilo();
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
        $silo = $this->createSilo();
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
        $silo = $this->createSilo();

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
        $silo = $this->createSilo();
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
        $silo = $this->createSilo();

        $this->expectException(\Silo\Exception::class);
        $this->expectExceptionMessage('Unsupported filter operator: INVALID');
        $silo->filter('age', 'INVALID', 'value');
    }

    public function testFilterLikeRequiresString(): void
    {
        $silo = $this->createSilo();

        $this->expectException(\Silo\Exception::class);
        $this->expectExceptionMessage('LIKE operator requires a string value');
        $silo->filter('name', 'LIKE', 42);
    }

    public function testFilterComparisonAcceptsInt(): void
    {
        $silo = $this->createSilo();

        // int/float are now accepted for comparison operators
        $silo->set('product', ['price' => '30']);
        $result = $silo->search(['where' => $silo->filter('price', '>', 25)]);
        $this->assertSame(1, $result['total']);
    }

    public function testFilterNumericComparison(): void
    {
        $silo = $this->createSilo();
        $silo->set('product', ['price' => '9']);
        $silo->set('product', ['price' => '30']);
        $silo->set('product', ['price' => '100']);

        $result = $silo->search(['where' => $silo->filter('price', '>', '30')]);
        $this->assertSame([3], $result['results']);

        $result = $silo->search(['where' => $silo->filter('price', '<', '30')]);
        $this->assertSame([1], $result['results']);

        $result = $silo->search(['where' => $silo->filter('price', '>=', '30')]);
        $this->assertSame([2, 3], $result['results']);

        $result = $silo->search(['where' => $silo->filter('price', '<=', '30')]);
        $this->assertSame([1, 2], $result['results']);
    }



    /* GROUP */

    public function testGroupOr(): void
    {
        $silo = $this->createSilo();
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
        $silo = $this->createSilo();

        $this->expectException(\Silo\Exception::class);
        $this->expectExceptionMessage('Group operator must be AND or OR, got XOR');
        $silo->group('xor', $silo->filter('class', '=', 'person'));
    }

    public function testGroupNotEnoughFilters(): void
    {
        $silo = $this->createSilo();

        $this->expectException(\Silo\Exception::class);
        $this->expectExceptionMessage('Group requires at least 2 filters');
        $silo->group('and');
    }
}
