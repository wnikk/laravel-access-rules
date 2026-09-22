<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Wnikk\LaravelAccessRules\Contracts\AccessManager;

#[AsCommand(name: 'acr:create')]
class AccessRuleCreate extends AccessCommand
{
    protected $signature = 'acr:create {rule} {title?} {options?} {description?} {parent_id?}
        {--resource= : Alias of the entity from config access.resources the rule is about}
        {--when= : Condition valid for everybody who has the rule}
        {--origin=code : "code" for a rule that code checks by name, "custom" for one that an admin panel may rename and delete}';

    protected $description = 'Access rules and inheritance: create new rule';

    public function handle(): int
    {
        $id = app(AccessManager::class)->newRule(
            $this->argument('rule'),
            $this->argument('title'),
            $this->argument('description'),
            $this->argument('parent_id') === null ? null : (int) $this->argument('parent_id'),
            $this->argument('options'),
            $this->option('resource'),
            $this->option('when'),
            $this->option('origin'),
        );

        if (! $id) {
            $this->error("Rule '{$this->argument('rule')}' was not created.");

            return self::FAILURE;
        }

        $this->info("Rule '{$this->argument('rule')}' created successfully.");

        return self::SUCCESS;
    }
}
