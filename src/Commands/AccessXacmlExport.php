<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Wnikk\LaravelAccessRules\Xacml\Xacml;

/**
 * Extends Command and not AccessCommand: that base class exists for commands that address one
 * owner by type and id, and an export covers all owners. All the work is in Xacml\Xacml, which
 * a controller calls the same way.
 */
#[AsCommand(name: 'acr:xacml:export')]
class AccessXacmlExport extends Command
{
    protected $signature = 'acr:xacml:export {target : The file to write, e.g. storage/app/access.xml}';

    protected $description = 'Access rules and inheritance: export owners, rules and permissions as one XACML 3.0 policy document';

    public function handle(Xacml $xacml): int
    {
        $target = (string) $this->argument('target');

        try {
            $warnings = $xacml->export($target);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        $this->info('Written to '.$target.', '.count($warnings).' warning(s).');

        return self::SUCCESS;
    }
}
