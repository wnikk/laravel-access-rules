<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Wnikk\LaravelAccessRules\AccessRules;

#[AsCommand(name: 'acr:create')]
class AccessRuleCreate extends AccessCommand
{
    protected $signature = 'acr:create {rule} {title?} {options?} {description?} {parent_id?}
        {--resource= : Alias of the entity from config access.resources the rule is about}
        {--when= : Condition valid for everybody who has the rule}';

    protected $description = 'Access rules and inheritance: create new rule';

    public function handle(): int
    {
        $id = AccessRules::newRule([
            'guard_name'  => $this->argument('rule'),
            'title'       => $this->argument('title'),
            'options'     => $this->argument('options'),
            'description' => $this->argument('description'),
            'parent_id'   => $this->argument('parent_id'),
            'resource'    => $this->option('resource'),
            'when'        => $this->option('when'),
        ]);

        if (! $id) {
            $this->error("Rule '{$this->argument('rule')}' was not created.");

            return self::FAILURE;
        }

        $this->info("Rule '{$this->argument('rule')}' created successfully.");

        return self::SUCCESS;
    }
}
