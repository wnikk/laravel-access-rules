<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Wnikk\LaravelAccessRules\Administration\Doctor;

/**
 * Prints what Administration\Doctor finds: rows of the four tables that the package would never
 * have written. For after an upgrade from 2.x, a restore, or SQL typed by hand. The command exits
 * with 1 when there is anything to print, like acr:lint.
 */
#[AsCommand(name: 'acr:doctor')]
class AccessDoctor extends AccessCommand
{
    protected $signature = 'acr:doctor
        {--fix : Delete duplicate permissions past the first copy and rows that point at nothing; put rules whose parent is gone at the top of the tree}';

    protected $description = 'Access rules and inheritance: find permissions and links the package would never have written';

    public function handle(Doctor $doctor): int
    {
        $result = $doctor->run((bool) $this->option('fix'));

        if ($result['fixed'] > 0) {
            $this->info($result['fixed'].' row(s) deleted or put right.');
        }

        if ($result['problems'] === []) {
            $this->info('Permissions and inheritance are consistent.');

            return self::SUCCESS;
        }

        $this->table(['Where', 'Problem'], array_map(static fn (array $found) => [$found['where'], $found['problem']], $result['problems']));
        $this->error(count($result['problems']).' problem(s) found.');

        return self::FAILURE;
    }
}
