<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'acr:inherit')]
class AccessPermissionInherit extends AccessCommand
{
    protected $signature = 'acr:inherit {primary_owner_type} {primary_owner_id} {owner_type} {owner_id}';

    protected $description = 'Access rules and inheritance: inherit rules form one user to second';

    public function handle(): int
    {
        if (! $this->owner()->inheritFrom($this->owner('primary_'))) {
            $this->error('Inherit permission failed.');

            return self::FAILURE;
        }

        $this->info('Inherit permissions added.');

        return self::SUCCESS;
    }
}
