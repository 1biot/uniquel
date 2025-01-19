<?php

namespace FQL\Functions\Utils;

use FQL\Exceptions\UnexpectedValueException;
use FQL\Functions\Core\NoFieldFunction;

/**
 * Generates cryptographically secure random bytes.
 *
 * Suitable for generating salts, keys, and nonces.
 */
final class RandomBytes extends NoFieldFunction
{
    public function __construct(private readonly int $length = 10)
    {
    }

    /**
     * @return string
     * @throws UnexpectedValueException
     */
    public function __invoke(): mixed
    {
        try {
            return random_bytes($this->length);
        } catch (\Exception $e) {
            throw new UnexpectedValueException('Failed to generate random bytes', previous: $e);
        }
    }

    /**
     * @throws UnexpectedValueException
     */
    public function __toString(): string
    {
        return sprintf('%s(%s)', $this->getName(), $this->length);
    }
}
