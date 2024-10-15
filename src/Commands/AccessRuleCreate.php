<?php

namespace Wnikk\LaravelAccessRules\Commands;

use Illuminate\Console\Command;
use Wnikk\LaravelAccessRules\AccessRules;

class AccessRuleCreate extends Command
{
    // Command signature and description
    protected $signature = 'acr:create {rule} {title?} {options?} {description?} {parent_id?}';
    protected $description = 'Access rules and inheritance: create new rule';

    public function handle()
    {
        // Get the rule name, title and optional params from the command arguments
        $rule  = $this->argument('rule');
        $title = $this->argument('title');
        $description = $this->argument('description');
        $options     = $this->argument('options');
        $parent_id   = $this->argument('parent_id');

        $id = AccessRules::newRule([
            'guard_name'  => $rule,
            'title'       => $title,
            'description' => $description,
            'options'     => $options,
            'parent_id'   => $parent_id,
        ]);

        if ($id) {
            $this->info("Rule '{$rule}' created successfully.");
        } else {
            $this->error("Rule '{$rule}' already exists.");
        }
    }
}