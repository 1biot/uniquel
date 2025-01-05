<?php

namespace UQL\Functions;

use UQL\Stream\ArrayStreamProvider;

/**
 * @phpstan-import-type StreamProviderArrayIteratorValue from ArrayStreamProvider
 */
interface Invokable
{
    public function getName(): string;

    /**
     * @param StreamProviderArrayIteratorValue $item
     * @param StreamProviderArrayIteratorValue $resultItem
     */
    public function __invoke(array $item, array $resultItem): mixed;
}
