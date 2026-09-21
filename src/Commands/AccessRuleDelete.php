<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;

#[AsCommand(name: 'acr:delete')]
class AccessRuleDelete extends AccessCommand
{
    protected $signature = 'acr:delete {rule} {--force : Delete together with every permission and prohibition for the rule. Without it a rule that somebody holds is not deleted}';

    protected $description = 'Access rules and inheritance: remove rule';

    public function handle(): int
    {
        try {
            $deleted = AccessRules::delRule($this->argument('rule'), (bool) $this->option('force'));
        } catch (AccessRulesException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $deleted) {
            $this->error("Rule '{$this->argument('rule')}' not found.");

            return self::FAILURE;
        }

        $this->info("Rule '{$this->argument('rule')}' deleted successfully.");

        return self::SUCCESS;
    }
}
