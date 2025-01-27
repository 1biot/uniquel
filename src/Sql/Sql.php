<?php

namespace FQL\Sql;

use FQL\Conditions\Condition;
use FQL\Enum;
use FQL\Exception;
use FQL\Functions;
use FQL\Interface;
use FQL\Query;
use FQL\Stream;
use FQL\Traits;

class Sql extends SqlLexer implements Interface\Parser
{
    use Traits\Helpers\StringOperations;

    public function __construct(private readonly string $sql)
    {
        $this->tokenize($this->sql);
    }

    /**
     * @throws Exception\InvalidFormatException
     * @throws Exception\FileNotFoundException
     */
    public function parse(): Interface\Results
    {
        return $this->toQuery()->execute();
    }

    /**
     * @throws Exception\FileNotFoundException
     * @throws Exception\InvalidFormatException
     */
    public function toQuery(): Interface\Query
    {
        $this->rewind();
        $stream = null;
        while (!$this->isEOF()) {
            $token = $this->nextToken();
            if (strtoupper($token) !== 'FROM') {
                continue;
            }

            $fileQuery = new Query\FileQuery($this->nextToken());
            $stream = Stream\Provider::fromFile($fileQuery->file, $fileQuery->extension);
            break;
        }

        if ($stream === null) {
            throw new Exception\UnexpectedValueException('No query found');
        }

        return $this->parseWithQuery($stream->query());
    }

    /**
     * @throws Exception\UnexpectedValueException
     * @throws Exception\InvalidFormatException
     * @throws Exception\FileNotFoundException
     */
    public function parseWithQuery(Interface\Query $query): Interface\Query
    {
        $this->rewind();
        while (!$this->isEOF()) {
            $token = $this->nextToken();
            switch (strtoupper($token)) {
                case 'SELECT':
                    $this->parseFields($query);
                    break;

                case 'FROM':
                    $fileQuery = new Query\FileQuery($this->nextToken());
                    $query->from($fileQuery->query ?? '');
                    break;

                case 'INNER':
                case 'LEFT':
                    $this->nextToken(); // Consume "JOIN"
                    $joinQuery = $this->nextToken();
                    $this->expect('AS');
                    $alias = $this->nextToken();
                    if (strtolower($token) === 'left') {
                        $query->leftJoin(Query\Provider::fromFileQuery($joinQuery), $alias);
                    } elseif (strtolower($token) === 'inner') {
                        $query->innerJoin(Query\Provider::fromFileQuery($joinQuery), $alias);
                    }

                    $this->expect('ON');

                    $field = $this->nextToken();
                    $operator = Enum\Operator::fromOrFail($this->nextToken());
                    $value = Enum\Type::matchByString($this->nextToken());
                    $query->on($field, $operator, $value);
                    break;
                case 'JOIN':
                    $joinQuery = $this->nextToken();
                    $this->expect('AS');
                    $alias = $this->nextToken();

                    $query->innerJoin(Query\Provider::fromFileQuery($joinQuery), $alias);
                    $this->expect('ON');

                    $field = $this->nextToken();
                    $operator = Enum\Operator::fromOrFail($this->nextToken());
                    $value = Enum\Type::matchByString($this->nextToken());

                    $query->on($field, $operator, $value);
                    break;

                case 'HAVING':
                case 'WHERE':
                    $this->parseConditions($query, strtolower($token));
                    break;

                case 'GROUP':
                    $this->expect('BY');
                    $this->parseGroupBy($query);
                    break;

                case 'ORDER':
                    $this->expect('BY');
                    $this->parseSort($query);
                    break;

                case 'OFFSET':
                    $limit = (int) $this->nextToken();
                    $query->offset($limit);
                    break;

                case 'LIMIT':
                    $limit = (int) $this->nextToken();
                    $offset = $this->nextToken();
                    $query->limit($limit, $offset === '' ? null : (int) $offset);
                    break;

                default:
                    throw new Exception\UnexpectedValueException("Unexpected token: $token");
            }
        }

        return $query;
    }

    private function parseFields(Interface\Query $query): void
    {
        while (!$this->isEOF() && !$this->isNextControlledKeyword()) {
            $field = $this->nextToken();
            if ($field === ',') {
                continue;
            } elseif ($field === 'DISTINCT') {
                $query->distinct();
                continue;
            }

            if ($this->isFunction($field)) {
                $this->applyFunctionToQuery($field, $query);
            } else {
                $query->select($field);
            }

            if (strtoupper($this->peekToken()) === 'AS') {
                $this->nextToken();
                $alias = $this->nextToken();
                $query->as($alias);
            }
        }
    }

    private function isFunction(string $token): bool
    {
        return preg_match('/\b(?!_)[A-Z0-9_]{2,}(?<!_)\(.*?\)/i', $token) === 1;
    }

    private function getFunction(string $token): string
    {
        return preg_replace('/(\b(?!_)[A-Z0-9_]{2,}(?<!_))\(.*?\)/i', '$1', $token);
    }

    /**
     * @param string $token
     * @return array<scalar|null>
     */
    private function getFunctionArguments(string $token): array
    {
        preg_match('/\b(?!_)[A-Z0-9_]{2,}(?<!_)\((.*?)\)/i', $token, $matches);
        return array_values(
            array_filter(
                array_map(
                    fn ($value) => $this->isQuoted($value) ? $this->removeQuotes($value) : $value,
                    array_map('trim', explode(',', $matches[1] ?? ''))
                )
            )
        );
    }

    /**
     * @param string $field
     * @param Interface\Query $query
     * @return void
     */
    private function applyFunctionToQuery(string $field, Interface\Query $query): void
    {
        $functionName = $this->getFunction($field);
        $arguments = $this->getFunctionArguments($field);

        match (strtoupper($functionName)) {
            // aggregate
            'AVG' => $query->avg((string) ($arguments[0] ?? '')),
            'COUNT' => $query->count((string) ($arguments[0] ?? '')),
            'GROUP_CONCAT' => $query->groupConcat((string) ($arguments[0] ?? ''), (string) ($arguments[1] ?? ',')),
            'MAX' => $query->max((string) ($arguments[0] ?? '')),
            'MIN' => $query->min((string) ($arguments[0] ?? '')),
            'SUM' => $query->sum((string) ($arguments[0] ?? '')),

            // hashing
            'MD5' => $query->md5((string) ($arguments[0] ?? '')),
            'SHA1' => $query->sha1((string) ($arguments[0] ?? '')),

            // math
            'CEIL' => $query->ceil((string) ($arguments[0] ?? '')),
            'FLOOR' => $query->floor((string) ($arguments[0] ?? '')),
            'MOD' => $query->modulo((string) ($arguments[0] ?? ''), (int) ($arguments[1] ?? 0)),
            'ROUND' => $query->round((string) ($arguments[0] ?? ''), (int) ($arguments[1] ?? 0)),

            // string
            'BASE64_DECODE' => $query->toBase64((string) ($arguments[0] ?? '')),
            'BASE64_ENCODE' => $query->fromBase64((string) ($arguments[0] ?? '')),
            'CONCAT' => $query->concat(
                ...array_map(
                    fn ($value) => Enum\Type::castValue($value, Enum\Type::STRING),
                    $arguments
                )
            ),
            'CONCAT_WS' => $query->concatWithSeparator((string) ($arguments[0] ?? ''), ...array_slice($arguments, 1)),
            'EXPLODE' => $query->explode((string) ($arguments[0] ?? ''), (string) ($arguments[1] ?? ',')),
            'IMPLODE' => $query->implode((string) ($arguments[0] ?? ''), (string) ($arguments[1] ?? ',')),
            'LENGTH' => $query->length((string) ($arguments[0] ?? '')),
            'LOWER' => $query->lower((string) ($arguments[0] ?? '')),
            'RANDOM_STRING' => $query->randomString((int) ($arguments[0] ?? 10)),
            'REVERSE' => $query->reverse((string) ($arguments[0] ?? '')),
            'UPPER' => $query->upper((string) ($arguments[0] ?? '')),

            // utils
            'COALESCE' => $query->coalesce(...$arguments),
            'COALESCE_NE' => $query->coalesceNotEmpty(...$arguments),
            'RANDOM_BYTES' => $query->randomBytes((int) ($arguments[0] ?? 10)),
            default => throw new Exception\UnexpectedValueException("Unknown function: $functionName"),
        };
    }

    private function parseConditions(Interface\Query $query, string $context): void
    {
        $logicalOperator = Enum\LogicalOperator::AND;
        $firstIter = true;
        while (!$this->isEOF() && !$this->isNextControlledKeyword()) {
            $token = strtoupper($this->peekToken());

            if ($token === 'AND') {
                $logicalOperator = Enum\LogicalOperator::AND;
                $this->nextToken(); // Consume "AND"
                continue;
            }

            if ($token === 'OR') {
                $logicalOperator = Enum\LogicalOperator::OR;
                $this->nextToken(); // Consume "OR"
                continue;
            }

            if ($token === 'XOR') {
                $logicalOperator = Enum\LogicalOperator::XOR;
                $this->nextToken(); // Consume "OR"
                continue;
            }

            // Parse a single condition
            $field = $this->nextToken();
            $operator = $this->nextToken();
            if (in_array($operator, ['IS', 'NOT', 'LIKE', 'IN'])) {
                $nextToken = $this->nextToken();
                if (in_array($nextToken, ['NOT', 'LIKE', 'IN'])) {
                    $operator .= ' ' . $nextToken;
                } else {
                    $this->rewindToken();
                }
            }

            $operator = Enum\Operator::fromOrFail($operator);
            $value = Enum\Type::matchByString($this->nextToken());
            if ($firstIter && $context === Condition::WHERE && $logicalOperator === Enum\LogicalOperator::AND) {
                $query->where($field, $operator, $value);
                $firstIter = false;
                continue;
            } elseif ($firstIter && $context === Condition::HAVING && $logicalOperator === Enum\LogicalOperator::AND) {
                $query->having($field, $operator, $value);
                $firstIter = false;
                continue;
            }

            if ($logicalOperator === Enum\LogicalOperator::AND) {
                $query->and($field, $operator, $value);
            } elseif ($logicalOperator === Enum\LogicalOperator::OR) {
                $query->or($field, $operator, $value);
            } else {
                $query->xor($field, $operator, $value);
            }
        }
    }

    private function parseGroupBy(Interface\Query $query): void
    {
        while (!$this->isEOF() && !$this->isNextControlledKeyword()) {
            $field = $this->nextToken();
            if ($field === ',') {
                continue;
            }

            $query->groupBy($field);
        }
    }

    private function parseSort(Interface\Query $query): void
    {
        while (!$this->isEOF() && !$this->isNextControlledKeyword()) {
            $field = $this->nextToken();
            if ($field === ',') {
                continue;
            }

            $directionString = strtoupper($this->nextToken());
            $direction = match ($directionString) {
                'ASC' => Enum\Sort::ASC,
                'DESC' => Enum\Sort::DESC,
                'SHUFFLE' => Enum\Sort::SHUFFLE,
                'NATSORT' => Enum\Sort::NATSORT,
                default => throw new Exception\SortException(sprintf('Invalid direction %s', $directionString)),
            };
            $query->orderBy($field, $direction);
        }
    }
}
