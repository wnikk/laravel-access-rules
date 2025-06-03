<?php
namespace Wnikk\LaravelAccessRules\Commands;

class AccessPermissionRemove extends AccessArguments
{
    // Command signature and description
    protected $signature = 'acr:remove {owner_type} {owner_id} {rule} {option?} {availability?}';
    protected $description = 'Access rules and inheritance: remove rule from user permissions';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $rule   = $this->argument('rule');
        $option = $this->argument('option');
        $acr    = $this->getDefaultAccessRules();

        if ($this->checkAvailabilityArgument()) {
            $access = 'Permission';
            $save   = $acr->remPermission($rule, $option);
        }
        else {
            $access = 'Prohibition';
            $save   = $acr->remProhibition($rule, $option);
        }

        if ($save) {
            $this->info("{$access} '{$rule}' removed from user with.");
        } else {
            $this->error("Error remove {$access} '{$rule}' from user.");
        }
    }
}