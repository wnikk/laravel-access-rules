<?php

namespace Wnikk\LaravelAccessRules\Exceptions;

use Wnikk\LaravelAccessRules\AccessRules;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

class AccessAuthorizationException extends AuthorizationException
{
    /**
     * Name of last disallow permission
     *
     * @var string
     */
    protected $nameRule;

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
        $this->nameRule = AccessRules::getLastDisallowPermission();

        if (!$message && $this->nameRule)
        {
            $message = 'Action "'.$this->nameRule.'" is unauthorized.';
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * Return name of last disallow permission
     *
     * @return string|null
     */
    public function getLastRule(): ?string
    {
        return $this->nameRule;
    }
}
