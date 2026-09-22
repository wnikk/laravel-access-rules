<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use InvalidArgumentException;
use Wnikk\LaravelAccessRules\Administration\OwnerAccess;
use Wnikk\LaravelAccessRules\Contracts\AccessManager;

/**
 * Shared part of the "acr:" commands: selecting an owner from arguments.
 *
 * The arguments are those of version 2: a type, an id, a rule. PromptsForMissingInput makes
 * artisan ask for a forgotten argument instead of failing with a usage line.
 */
abstract class AccessCommand extends Command implements PromptsForMissingInput
{
    /**
     * The owner has to exist. A command that created owners on the fly would turn a typo in an id
     * into a new, empty owner that receives the permission instead of the intended one.
     *
     * @throws InvalidArgumentException
     */
    protected function owner(string $prefix = ''): OwnerAccess
    {
        $access = app(AccessManager::class)->for($this->argument($prefix.'owner_type'), $this->argument($prefix.'owner_id'));

        if (! $access->record()) {
            throw new InvalidArgumentException('Owner '.$this->argument($prefix.'owner_type').' #'.$this->argument($prefix.'owner_id').' not found');
        }

        return $access;
    }

    /**
     * "yes" grants a permission, "no" a prohibition. Missing means "yes", as in version 2.
     */
    protected function availability(): bool
    {
        return match (strtolower((string) ($this->argument('availability') ?? 'yes'))) {
            '1', 'yes', 'y', 'true' => true,
            '0', 'no', 'n', 'false' => false,
            default                 => throw new InvalidArgumentException('Invalid availability parameter, acceptable values: yes, no'),
        };
    }
}
