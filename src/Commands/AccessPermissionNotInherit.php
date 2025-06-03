<?php
namespace Wnikk\LaravelAccessRules\Commands;

class AccessPermissionNotInherit extends AccessArguments
{
    // Command signature and description
    protected $signature = 'acr:not-inherit {primary_owner_type} {primary_owner_id} {owner_type} {owner_id}';
    protected $description = 'Access rules and inheritance: remove inherit rules form one user to second';

    public function handle()
    {
        $pAcr = $this->getPrimaryAccessRules();
        $acr  = $this->getDefaultAccessRules();

        $save = $acr->getOwner()->remInheritance(
            $pAcr->getOwner()
        );
        // Or you can use User trait method for inherit permissions from another user
        // $user1->remInheritFrom($user2);

        if ($save) {
            $this->info('Inherit permissions removed.');
        } else {
            $this->error('Remove inherit permission failed.');
        }
    }
}