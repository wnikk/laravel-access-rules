<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Exceptions;

use LogicException;

/**
 * A mistake in how the package is used or configured.
 *
 * It extends LogicException because that is what version 2 threw, and code that catches it
 * keeps working. The numeric code tells what went wrong, so calling code does not match on
 * message text that the next release may reword.
 *
 * One class with codes, not a class per mistake: callers either show the message or branch
 * on one or two cases, and eight near empty classes serve neither better.
 */
class AccessRulesException extends LogicException
{
    public const OWNER_NOT_FOUND = 1;

    public const RULE_NOT_FOUND = 2;

    public const DUPLICATE_PERMISSION = 3;

    public const INVALID_OPTION = 4;

    public const INHERITANCE_LOOP = 5;

    public const UNKNOWN_OWNER_TYPE = 6;

    public const OWNER_NOT_SELECTED = 7;

    public const INVALID_CONDITION = 8;
}
