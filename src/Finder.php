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
     *   - 'limit' (int)        : maximum number of results.
     *   - 'offset' (int)       : result offset for pagination.
     *   - 'debug' (bool)       : return the generated SQL instead of executing it.
     *
     * @param array{where?: string, order?: string|string[], limit?: int, offset?: int, debug?: bool} $arg
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
            $sql[] = 'LIMIT ' . max(0, (int) $arg['limit']);
        }

        if (isset($arg['offset'])) {
            $sql[] = 'OFFSET ' . max(0, (int) $arg['offset']);
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
            // Strip LIMIT/OFFSET for the separate COUNT query (SQLite, PostgreSQL, etc.)
            $countSql = preg_replace('/\n(LIMIT|OFFSET)\s+\d+$/i', '', $sql);
            $countSql = preg_replace('/\n(LIMIT|OFFSET)\s+\d+$/i', '', $countSql);
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
     *
     * @param string|int|float|string[]|int[] $value
     */
    public function filter(string $field, string $operator, string|int|float|array $value): string
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
            case '<>':
                if (is_int($value) || is_float($value)) {
                    $sql .= $operator . $value;
                } else {
                    $sql .= $operator . $this->pdo->quote($value);
                }
                break;
            case '<=':
            case '>=':
            case '<':
            case '>':
                $quoted = $this->pdo->quote((string) $value);
                if ($field !== 'id' && $field !== 'class' && (is_int($value) || is_float($value))) {
                    $sql = 'attribute=' . $this->pdo->quote($field) . ' AND CAST(value AS NUMERIC) ' . $operator . ' ' . $value;
                } elseif ($field !== 'id' && $field !== 'class' && is_numeric($value)) {
                    $sql = 'attribute=' . $this->pdo->quote($field) . ' AND CAST(value AS NUMERIC) ' . $operator . ' CAST(' . $quoted . ' AS NUMERIC)';
                } else {
                    $sql .= $operator . $quoted;
                }
                break;
            case 'LIKE':
                if (!is_string($value)) {
                    throw new Exception('LIKE operator requires a string value');
                }
                $sql .= ' ' . $operator . ' ' . $this->pdo->quote($value);
                break;
            case 'IN':
                $list = is_array($value) ? $value : explode(',', $value);
                foreach ($list as $k => $v) {
                    $list[$k] = is_numeric($v) ? (string) $v : $this->pdo->quote(trim((string) $v));
                }
                $sql .= ' IN ( ' . implode(', ', $list) . ' )';
                break;
            default:
                throw new Exception('Unsupported filter operator: ' . $operator);
        }

        return $sql;
    }

    /**
     * Combine multiple filter fragments with AND / OR logic.
     *
     * @param string $operator AND or OR.
     * @param string ...$filters Filter fragments from filter().
     */
    public function group(string $operator, string ...$filters): string
    {
        $operator = strtoupper(trim($operator));
        if ($operator !== 'OR' && $operator !== 'AND') {
            throw new Exception('Group operator must be AND or OR, got ' . $operator);
        }

        if (count($filters) < 2) {
            throw new Exception('Group requires at least 2 filters');
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
