<?php
namespace Wnikk\LaravelAccessRules\Commands;

use Wnikk\LaravelAccessRules\AccessRules;
use Illuminate\Console\Command;
use InvalidArgumentException;

abstract class AccessArguments extends Command
{
    /**
     * Checks the availability argument for valid values.
     *
     * @return bool
     * @throws InvalidArgumentException
     */
    protected function checkAvailabilityArgument(): bool
    {
        $access = $this->argument('availability');
        $access = strtolower($access);
        if (in_array($access, ['1', 'yes', 'y']))
        {
            return true;
        }
        elseif (in_array($access, ['0', 'no', 'n']))
        {
            return false;
        }
        else {
            throw new InvalidArgumentException('Invalid availability parameter, acceptable values: yes, no');
        }
    }

    /**
     * Retrieves the owner based on the provided owner type and ID.
     *
     * @return AccessRules
     * @throws InvalidArgumentException
     */
    protected function getDefaultAccessRules(): AccessRules
    {
        $ownerType = $this->argument('owner_type');
        $ownerId   = $this->argument('owner_id');

        $acr = new AccessRules;
        $acr->setOwner($ownerType, $ownerId);
        $user = $acr->getOwner();

        if (!$user) {
            throw new InvalidArgumentException('User not found');
        }
        return $acr;
    }

    /**
     * Retrieves the owner based on the provided owner type and ID.
     *
     * @return AccessRules
     * @throws InvalidArgumentException
     */
    protected function getPrimaryAccessRules(): AccessRules
    {
        $primaryOwnerType = $this->argument('primary_owner_type');
        $primaryOwnerId   = $this->argument('primary_owner_id');

        $acr = new AccessRules;
        $acr->setOwner($primaryOwnerType, $primaryOwnerId);
        $user = $acr->getOwner();

        if (!$user) {
            throw new InvalidArgumentException('User not found');
        }
        return $acr;
    }
}