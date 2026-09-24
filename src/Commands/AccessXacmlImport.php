<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Wnikk\LaravelAccessRules\Xacml\Xacml;

/**
 * Prints the report of Xacml\Xacml and nothing more. A user interface gets the same report
 * as an array and draws it its own way.
 */
#[AsCommand(name: 'acr:xacml:import')]
class AccessXacmlImport extends Command
{
    protected $signature = 'acr:xacml:import {source : An export of this package, or a foreign XACML 3.0 document}
        {--check : Show what the import would change and write nothing}
        {--all : Also list what is the same in the document and in the database}
        {--replace : Bring rules, names of owners and conditions that differ to the document. Without it they stay as they are}
        {--partial : Write what converts even when something does not. Prohibitions that do not convert leave access wider than the document means}
        {--subject-type= : Owner type for a subject-id that comes without a type, one of config access.owner_types}
        {--role-type=Role : Owner type for a role that comes without a type}
        {--everyone= : "Type:id" of the owner that receives rules addressed to no subject and no role}';

    protected $description = 'Access rules and inheritance: convert an XACML 3.0 policy into owners, rules and permissions, or only show what it would change';

    public function handle(Xacml $xacml): int
    {
        $options = [
            'replace'      => (bool) $this->option('replace'),
            'partial'      => (bool) $this->option('partial'),
            'subject_type' => $this->option('subject-type'),
            'role_type'    => (string) $this->option('role-type'),
            'everyone'     => $this->option('everyone'),
        ];

        try {
            $report = $this->option('check')
                ? $xacml->check($this->argument('source'), $options)
                : $xacml->import($this->argument('source'), $options);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $problems = [
            ...array_map(static fn (array $line) => ['error', ...$line], $report['errors']),
            ...array_map(static fn (array $line) => ['warning', ...$line], $report['warnings']),
        ];
        if ($problems !== []) {
            $this->table(['', 'Where', 'What'], $problems);
        }

        $changes = array_filter($report['changes'], fn (array $change) => $this->option('all') || $change['action'] !== 'same');
        if ($changes !== []) {
            $this->table(['Kind', 'Action', 'What', 'Document', 'Database'], array_map(static fn (array $c) => [$c['kind'], str_replace('_', ' ', $c['action']), $c['what'], $c['document'] ?? '', $c['database'] ?? ''], $changes));
        }

        foreach ($report['summary'] as $kind => $actions) {
            $this->line($kind.': '.implode(', ', array_map(static fn ($count, $action) => $count.' '.str_replace('_', ' ', $action), $actions, array_keys($actions))));
        }

        if ($report['written']) {
            $this->info('Written: '.implode(', ', array_map(static fn ($count, $what) => $count.' '.$what, $report['applied'], array_keys($report['applied']))).'.');
        } elseif (! $this->option('check')) {
            $this->error('Nothing is written while there are errors. Fix the document, or pass --partial to write what converts.');
        }

        return $report['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
