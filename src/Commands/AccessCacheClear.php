<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Wnikk\LaravelAccessRules\Contracts\AccessManager;

#[AsCommand(name: 'acr:cache:clear')]
class AccessCacheClear extends AccessCommand
{
    protected $signature = 'acr:cache:clear';

    protected $description = 'Access rules and inheritance: leave behind all cached permissions';

    public function handle(): int
    {
        app(AccessManager::class)->flush();

        $this->info('Cached permissions cleared.');

        return self::SUCCESS;
    }
}
