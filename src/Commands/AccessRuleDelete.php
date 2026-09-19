<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Wnikk\LaravelAccessRules\AccessRules;

#[AsCommand(name: 'acr:delete')]
class AccessRuleDelete extends AccessCommand
{
    protected $signature = 'acr:delete {rule} {--force : Delete permanently, together with permissions}';

    protected $description = 'Access rules and inheritance: remove rule';

    public function handle(): int
    {
        if (! AccessRules::delRule($this->argument('rule'), (bool) $this->option('force'))) {
            $this->error("Rule '{$this->argument('rule')}' not found.");

            return self::FAILURE;
        }

        $this->info("Rule '{$this->argument('rule')}' deleted successfully.");

        return self::SUCCESS;
    }
}
