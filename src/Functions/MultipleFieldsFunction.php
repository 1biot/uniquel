<?php

namespace UQL\Functions;

abstract class MultipleFieldsFunction extends BaseFunction
{
    /** @var string[] $fields */
    protected readonly array $fields;

    public function __construct(string ...$fields)
    {
        $this->fields = $fields;
    }

    public function __toString()
    {
        return sprintf(
            '%s(%s)',
            $this->getName(),
            implode(', ', $this->fields)
        );
    }
}
