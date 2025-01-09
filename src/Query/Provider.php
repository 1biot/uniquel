<?php

namespace UQL\Query;

use UQL\Stream\Csv;
use UQL\Stream\Json;
use UQL\Stream\JsonStream;
use UQL\Stream\Neon;
use UQL\Stream\Xml;
use UQL\Stream\Yaml;
use UQL\Traits;

final class Provider implements Query, \Stringable
{
    use Traits\Conditions;
    use Traits\From;
    use Traits\Groupable;
    use Traits\Joinable;
    use Traits\Limit;
    use Traits\Select;
    use Traits\Sortable;

    public function __construct(private readonly Xml|Json|JsonStream|Yaml|Neon|Csv $stream)
    {
    }

    public function execute(): \UQL\Results\Stream
    {
        return new \UQL\Results\Stream(
            $this->stream,
            $this->selectedFields,
            $this->getFrom(),
            $this->contexts['where'],
            $this->contexts['having'],
            $this->joins,
            $this->groupByFields,
            $this->orderings,
            $this->limit,
            $this->offset
        );
    }

    public function test(): string
    {
        return trim((string) $this);
    }

    public function __toString(): string
    {
        $queryParts = [];

        // SELECT
        $queryParts[] = $this->selectToString();
        // FROM
        $queryParts[] = $this->fromToString($this->stream->provideSource());
        // JOIN
        $queryParts[] = $this->joinsToString();
        // WHERE
        $queryParts[] = $this->conditionsToString('where');
        // HAVING
        $queryParts[] = $this->conditionsToString('having');
        // GROUP BY
        $queryParts[] = $this->groupByToString();
        // ORDER BY
        $queryParts[] = $this->orderByToString();
        // OFFSET
        $queryParts[] = $this->offsetToString();
        // LIMIT
        $queryParts[] = $this->limitToString();

        return str_replace("\t", "  ", implode('', $queryParts));
    }
}
