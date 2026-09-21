<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Wnikk\LaravelAccessRules\Administration\Linter;

/**
 * Prints what Administration\Linter finds, for CI and for deploys, right after migrations:
 * the command exits with 1 when there is anything to print. An admin panel calls the same
 * service and gets the same findings as data.
 */
#[AsCommand(name: 'acr:lint')]
class AccessLint extends AccessCommand
{
    protected $signature = 'acr:lint
        {--fix : Save again the conditions whose column types have changed since they were stored}';

    protected $description = 'Access rules and inheritance: check stored rules, conditions and owners against current models and config';

    public function handle(Linter $linter): int
    {
        $result = $linter->run((bool) $this->option('fix'));

        if ($result['fixed'] > 0) {
            $this->info($result['fixed'].' condition(s) saved again with current column types.');
        }

        if ($result['problems'] === []) {
            $this->info('Rules, conditions and owners match current models and config.');

            return self::SUCCESS;
        }

        $this->table(['Where', 'Problem'], array_map(static fn (array $found) => [$found['where'], $found['problem']], $result['problems']));
        $this->error(count($result['problems']).' problem(s) found.');

        return self::FAILURE;
    }
}
