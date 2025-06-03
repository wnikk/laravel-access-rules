<?php
namespace Wnikk\LaravelAccessRules\Commands;


use Wnikk\LaravelAccessRules\AccessRules;
use Illuminate\Console\Command;

class AccessRuleDelete extends Command
{
    // Command signature and description
    protected $signature = 'acr:delete {rule}';
    protected $description = 'Access rules and inheritance: remove rule';

    public function handle()
    {
        // Get the rule name, title and optional params from the command arguments
        $rule  = $this->argument('rule');

        $result = AccessRules::delRule($rule);

        if ($result) {
            $this->info("Rule '{$rule}' deleted successfully.");
        } else {
            $this->error("Rule '{$rule}' not found.");
        }
    }
}