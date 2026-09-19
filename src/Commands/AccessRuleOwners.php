<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Wnikk\LaravelAccessRules\AccessRules;

#[AsCommand(name: 'acr:owners')]
class AccessRuleOwners extends AccessCommand
{
    protected $signature = 'acr:owners';

    protected $description = 'Access rules and inheritance: display owners type list';

    public function handle(): int
    {
        $rows = [];
        foreach (AccessRules::getListTypes() as $id => $name) {
            $rows[] = [$id, $name];
        }

        $this->table(['ID', 'Type'], $rows);

        return self::SUCCESS;
    }
}
