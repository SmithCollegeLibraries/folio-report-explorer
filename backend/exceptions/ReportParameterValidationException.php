<?php

namespace app\exceptions;

final class ReportParameterValidationException extends \InvalidArgumentException
{
    private $fieldErrors;

    public function __construct(string $field, string $message)
    {
        parent::__construct($message);
        $this->fieldErrors = [$field => $message];
    }

    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
