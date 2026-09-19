<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Exceptions;

/**
 * A condition cannot be parsed, or names something that does not exist or is not allowed.
 *
 * ConditionCompiler throws it while a condition is saved. It never comes out of a permission
 * check: a stored condition has passed these checks already.
 */
class InvalidConditionException extends AccessRulesException
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, self::INVALID_CONDITION, $previous);
    }
}
