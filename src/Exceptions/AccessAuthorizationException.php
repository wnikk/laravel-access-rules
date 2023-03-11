<?php

namespace Wnikk\LaravelAccessRules\Exceptions;

use Wnikk\LaravelAccessRules\AccessRules;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

class AccessAuthorizationException extends AuthorizationException
{
    /**
     * Create a new authorization exception instance.
     *
     * @param  string|null  $message
     * @param  mixed  $code
     * @param  Throwable|null  $previous
     * @return void
     */
    public function __construct($message = null, $code = null, Throwable $previous = null)
    {
        if (!$message) {
            $message = AccessRules::getLastDisallowPermission();
            if($message) $message = 'Action "'.$message.'" is unauthorized.';
        }
        parent::__construct($message, $code, $previous);
    }
}
