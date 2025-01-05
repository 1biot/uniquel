<?php

namespace UQL\Functions;

use UQL\Enum\Type;
use UQL\Exceptions\InvalidArgumentException;

final class Floor extends SingleFieldFunction
{
    /**
     * @inheritDoc
     * @return float|int
     */
    public function __invoke(array $item, array $resultItem): mixed
    {
        $value = $this->getFieldValue($this->field, $item, $resultItem) ?? '';
        if (is_string($value)) {
            $value = Type::matchByString($value);
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Field "%s" value is not numeric: %s',
                    $this->field,
                    $value
                )
            );
        }
        return floor($value);
    }
}
