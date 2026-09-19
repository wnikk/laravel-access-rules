<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Exceptions;

use RuntimeException;

/**
 * A condition cannot become SQL, so a list cannot be filtered by it.
 *
 * Only a custom function applied to columns of the record causes it, for example
 * "half(order.cost) > 100". The same condition still works for a loaded record. The caller
 * loads the rows and checks them one by one, or moves the computation into the database.
 *
 * It is a runtime exception, not an AccessRulesException: the condition is valid, the
 * situation it is used in is not.
 */
class UntranslatableConditionException extends RuntimeException {}
