<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'acr:not-inherit')]
class AccessPermissionNotInherit extends AccessCommand
{
    protected $signature = 'acr:not-inherit {primary_owner_type} {primary_owner_id} {owner_type} {owner_id}';

    protected $description = 'Access rules and inheritance: remove inherit rules form one user to second';

    public function handle(): int
    {
        if (! $this->owner()->stopInheritingFrom($this->owner('primary_'))) {
            $this->error('Remove inherit permission failed.');

            return self::FAILURE;
        }

        $this->info('Inherit permissions removed.');

        return self::SUCCESS;
    }
}
