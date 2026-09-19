<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'acr:assign')]
class AccessPermissionAssign extends AccessCommand
{
    protected $signature = 'acr:assign {owner_type} {owner_id} {rule} {option?} {availability?}
        {--when= : Condition of the permission, e.g. "order.cost > 100 && order.items.count < 3"}';

    protected $description = 'Access rules and inheritance: assign rule to user';

    public function handle(): int
    {
        $acr = $this->owner();

        $this->availability()
            ? $acr->addPermission($this->argument('rule'), $this->argument('option'), $this->option('when'))
            : $acr->addProhibition($this->argument('rule'), $this->argument('option'), $this->option('when'));

        $this->info('Permission assigned.');

        return self::SUCCESS;
    }
}
