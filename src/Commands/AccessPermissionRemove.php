<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'acr:remove')]
class AccessPermissionRemove extends AccessCommand
{
    protected $signature = 'acr:remove {owner_type} {owner_id} {rule} {option?} {availability?}';

    protected $description = 'Access rules and inheritance: remove rule from user permissions';

    public function handle(): int
    {
        $acr = $this->owner();

        $removed = $this->availability()
            ? $acr->remPermission($this->argument('rule'), $this->argument('option'))
            : $acr->remProhibition($this->argument('rule'), $this->argument('option'));

        if (! $removed) {
            $this->error('Permission not found.');

            return self::FAILURE;
        }

        $this->info('Permission removed.');

        return self::SUCCESS;
    }
}
