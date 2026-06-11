<?php

namespace app\exceptions;

/**
 * Raised when a generated query references data blocked by reporting policy
 * (PII/restricted schemas or tables). Subclasses InvalidArgumentException so
 * existing `catch (\InvalidArgumentException)` handlers continue to work, while
 * letting callers branch on the type to return an HTTP 403 policy block instead
 * of relying on substring-matching the exception message.
 */
class PolicyViolationException extends \InvalidArgumentException
{
}
