<?php
namespace Wnikk\LaravelAccessRules\Commands;

class AccessPermissionInherit extends AccessArguments
{
    // Command signature and description
    protected $signature = 'acr:inherit {primary_owner_type} {primary_owner_id} {owner_type} {owner_id}';
    protected $description = 'Access rules and inheritance: inherit rules form one user to second';

    public function handle()
    {
        $pAcr = $this->getPrimaryAccessRules();
        $acr  = $this->getDefaultAccessRules();

        $save = $acr->getOwner()->addInheritance(
            $pAcr->getOwner()
        );
        // Or you can use User trait method for inherit permissions from another user
        // $user1->inheritPermissionFrom($user2);

        if ($save) {
            $this->info('Inherit permissions added.');
        } else {
            $this->error('Inherit permission failed.');
        }
    }
}