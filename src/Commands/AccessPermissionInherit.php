<?php

namespace Wnikk\LaravelAccessRules\Commands;

use Illuminate\Console\Command;
use Wnikk\LaravelAccessRules\AccessRules;

class AccessPermissionInherit extends Command
{
    // Command signature and description
    protected $signature = 'acr:inherit {primary_owner_type} {primary_owner_id} {owner_type} {owner_id}';
    protected $description = 'Access rules and inheritance: inherit rules form one user to second';

    public function handle()
    {
        $primaryOwnerType = $this->argument('primary_owner_type');
        $primaryOwnerId   = $this->argument('primary_owner_id');
        $secondOwnerType  = $this->argument('owner_type');
        $secondOwnerId    = $this->argument('owner_id');

        $acr = new AccessRules;
        $acr->setOwner($secondOwnerType, $secondOwnerId);
        $user = $acr->getOwner();

        if (!$user) {
            $this->error('User not found!');
            return;
        }

        $save = $user->inheritPermissionFrom($primaryOwnerType, $primaryOwnerId);

        if ($save) {
            $this->info('Inherit permissions added.');
        } else {
            $this->error('Inherit permission failed.');
        }
    }
}