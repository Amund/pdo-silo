<?php

declare(strict_types=1);

namespace Silo;

use PDO;

/**
 * Search and filter resources.
 *
 * This class is used internally by Silo.
 */
class Finder
{
    private PDO $pdo;
    private string $prefix;
    private string $driver;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
        $this->driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Search resources with optional filtering, ordering, and limits.
     *
     * Supported $arg keys:
     *   - 'where' (string)  : SQL WHERE fragment from filter() / group().
     *   - 'order' (string|array): attribute name(s) with optional ASC/DESC.
     *   - 'limit' (string|int)   : LIMIT clause value.
     *   - 'debug' (bool)         : return the generated SQL instead of executing it.
     *
     * @param array{where?: string, order?: string|string[], limit?: string|int, debug?: bool} $arg        Query parameters.
     * @return ($arg is array{debug?: true} ? string[] : array{total: int, results: int[], duration: string})
     */
    public function search(array $arg = []): array
    {
        $sql = [
            'SELECT id',
            'FROM ' . $this->prefix . '_meta',
            'LEFT JOIN ' . $this->prefix . '_attribute USING ( id )',
        ];

        if (isset($arg['where'])) {
            $sql[] = 'WHERE ' . $arg['where'];
        }

        $sql[] = 'GROUP BY id';

        if (isset($arg['order'])) {
            $sql = $this->buildOrderSql($sql, $arg['order']);
        }

        if (isset($arg['limit'])) {
            $sql[] = 'LIMIT ' . $arg['limit'];
        }

        $sql = implode("\n", $sql);

        if (!empty($arg['debug'])) {
            return [$sql];
        }

        $start = microtime(true);

        $total = null;

        if ($this->driver === 'mysql') {
            $sqlCountAware = preg_replace('#^SELECT#', 'SELECT SQL_CALC_FOUND_ROWS', $sql);
            $stmt = $this->pdo->query($sqlCountAware);
            $total = $this->pdo->query('SELECT FOUND_ROWS()')->fetch(PDO::FETCH_COLUMN);
        } else {
            // Strip LIMIT for the separate COUNT query (SQLite, PostgreSQL, etc.)
            $countSql = preg_replace('/\nLIMIT\s+\d+$/i', '', $sql);
            $countSql = 'SELECT COUNT(*) FROM (' . $countSql . ') AS count_sub';
            $total = $this->pdo->query($countSql)->fetch(PDO::FETCH_COLUMN);
            $stmt = $this->pdo->query($sql);
        }

        $results = [];
        while ($d = $stmt->fetch(PDO::FETCH_COLUMN)) {
            $results[] = (int) $d;
        }

        $stop = microtime(true);

        return [
            'total' => (int) $total,
            'results' => $results,
            'duration' => number_format(round($stop - $start, 6), 6),
        ];
    }

    /**
     * Build a WHERE clause fragment.
     *
     * Supported operators: =, !=, <, >, <=, >=, <>, LIKE, IN.
     */
    public function filter(string $field, string $operator, mixed $value): string
    {
        $sql = ' ';
        if ($field === 'id' || $field === 'class') {
            $sql = $field;
        } else {
            $sql = 'attribute=' . $this->pdo->quote($field) . ' AND value';
        }

        $operator = strtoupper($operator);
        switch ($operator) {
            case '=':
            case '!=':
            case '<=':
            case '>=':
            case '<':
            case '>':
            case '<>':
                if (!is_string($value)) {
                    throw new Exception('Bad Request');
                }
                $sql .= $operator . $this->pdo->quote($value);
                break;
            case 'LIKE':
                if (!is_string($value)) {
                    throw new Exception('Bad Request');
                }
                $sql .= ' ' . $operator . ' ' . $this->pdo->quote($value);
                break;
            case 'IN':
                $list = !is_array($value) ? explode(',', $value) : $value;
                foreach ($list as $k => $v) {
                    $list[$k] = $this->pdo->quote(trim($v));
                }
                $sql .= ' IN ( ' . implode(', ', $list) . ' )';
                break;
            default:
                throw new Exception('Bad Request');
        }

        return $sql;
    }

    /**
     * Combine multiple filter fragments with AND / OR logic.
     */
    public function group(): string
    {
        $filters = func_get_args();
        if (count($filters) < 2) {
            throw new Exception('Bad Request');
        }

        $operator = strtoupper(trim(array_shift($filters)));
        if ($operator !== 'OR' && $operator !== 'AND') {
            throw new Exception('Bad Request');
        }

        return ' ( ' . implode(' ) ' . $operator . ' ( ', $filters) . ' )';
    }

    /**
     * Wrap a flat SQL array into a subquery ordered by a given attribute.
     *
     * @param string[]          $sql   Current SQL parts.
     * @param string|string[]   $order One or more "field DIRECTION" specs.
     * @return string[]         SQL parts wrapped inside ordered subqueries.
     */
    private function buildOrderSql(array $sql, string|array $order): array
    {
        $parts = !is_array($order) ? array_map('trim', explode(',', $order)) : $order;

        foreach (array_reverse($parts) as $spec) {
            $segments = explode(' ', $spec);
            $field = $segments[0];
            $direction = (count($segments) === 2 && strtoupper($segments[1]) === 'DESC') ? 'DESC' : 'ASC';

            $inner = implode("\n\t", $sql);

            $sql = [
                'SELECT id',
                'FROM ' . $this->prefix . '_attribute',
                'WHERE id IN (',
                "\t" . $inner,
                ')',
                'AND attribute=' . $this->pdo->quote($field),
                'GROUP BY id',
                'ORDER BY value ' . $direction,
            ];
        }

        return $sql;
    }
}
