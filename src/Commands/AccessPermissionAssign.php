<?php
namespace Wnikk\LaravelAccessRules\Commands;

class AccessPermissionAssign extends AccessArguments
{
    // Command signature and description
    protected $signature = 'acr:assign {owner_type} {owner_id} {rule} {option?} {availability?}';
    protected $description = 'Access rules and inheritance: assign rule to user';

    public function handle()
    {
        $rule   = $this->argument('rule');
        $option = $this->argument('option');
        $acr    = $this->getDefaultAccessRules();

        if ($this->checkAvailabilityArgument())
        {
            $access = 'Permission';
            $save   = $acr->addPermission($rule, $option);
        }
        else {
            $access = 'Prohibition';
            $save   = $acr->addProhibition($rule, $option);
        }

        if ($save) {
            $this->info("{$access} '{$rule}' assigned to user.");
        } else {
            $this->error("Error assign {$access} '{$rule}' to user.");
        }
    }
}