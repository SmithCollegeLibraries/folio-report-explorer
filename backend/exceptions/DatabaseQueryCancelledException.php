<?php

namespace app\exceptions;

class DatabaseQueryCancelledException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Database query validation was cancelled.', 0, $previous);
    }
}
