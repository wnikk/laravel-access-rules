<?php
namespace Wnikk\LaravelAccessRules\Commands;

use Wnikk\LaravelAccessRules\AccessRules;
use Illuminate\Console\Command;

class AccessRuleOwners extends Command
{
    // Command signature and description
    protected $signature = 'acr:owners';
    protected $description = 'Access rules and inheritance: display owners type list';

    public function handle()
    {
        $list = AccessRules::getListTypes();
        $table = [];
        foreach ($list as $id => $type) {
            $table[] = [$id, $type];
        }
        $this->table(['id', 'name'], $table);
    }
}