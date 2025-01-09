<?php

namespace UQL\Exceptions;

class AliasException extends InvalidArgumentException
{
    public function __construct(string $message)
    {
        parent::__construct(sprintf('AS: %s', $message));
    }
}
