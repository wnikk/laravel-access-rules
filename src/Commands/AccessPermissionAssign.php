<?php

namespace Wnikk\LaravelAccessRules\Commands;

use Illuminate\Console\Command;
use Wnikk\LaravelAccessRules\AccessRules;

class AccessPermissionAssign extends Command
{
    // Command signature and description
    protected $signature = 'acr:assign {owner_type} {owner_id} {rule} {option?} {availability?}';
    protected $description = 'Access rules and inheritance: assign rule to user';

    public function handle()
    {
        $ownerType = $this->argument('owner_type');
        $ownerId   = $this->argument('owner_id');
        $rule      = $this->argument('rule');
        $option    = $this->argument('option');
        $access    = $this->argument('availability');

        $acr = new AccessRules;

        $acr->setOwner($ownerType, $ownerId);
        $user = $acr->getOwner();

        if (!$user) {
            $this->error('User not found');
            return;
        }


        if ($access) {$access = strtolower($access);}
        if ($access === null || $access === '1' || $access === 'yes' || $access === 'y')
        {
            $access = 'Permission';
            $save   = $acr->addPermission($rule, $option);
        }
        elseif ($access === '0' || $access === 'no' || $access === 'n')
        {
            $access = 'Prohibition';
            $save   = $acr->addProhibition($rule, $option);
        }
        else {
            $this->error('Invalid availability parameter, acceptable values: yes, no');
            return;
        }


        if ($save) {
            $this->info("{$access} '{$rule}' assigned to user with ID {$ownerId}.");
        } else {
            $this->error("Error assign {$access} '{$rule}' to user ID {$ownerId}.");
        }
    }
}