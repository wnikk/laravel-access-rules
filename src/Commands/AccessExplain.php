<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Wnikk\LaravelAccessRules\Contracts\AccessManager;
use Wnikk\LaravelAccessRules\Internal\Authorization\Explainer;
use Wnikk\LaravelAccessRules\Internal\Conditions\ResourceRegistry;

/**
 * "Why can't Ann see order 17", answered from the console:
 *
 *     php artisan acr:explain "App\Models\User" 5 orders.view order:17
 *
 * The record is named by the alias of its model from config access.resources. Loading it by alias
 * and id keeps class names and arbitrary queries out of a command that support staff runs.
 */
#[AsCommand(name: 'acr:explain')]
class AccessExplain extends AccessCommand
{
    protected $signature = 'acr:explain {owner_type} {owner_id} {ability}
        {record? : "order:17" for one record, "order" for records in general, nothing for a check without a record}';

    protected $description = 'Access rules and inheritance: explain why an ability is permitted or not';

    public function handle(AccessManager $manager, ResourceRegistry $resources): int
    {
        $type = $this->argument('owner_type');

        // A user model is loaded, so conditions can read "user." attributes. A role has no model.
        $owner = is_string($type) && is_subclass_of($type, Model::class) ? $type::query()->find($this->argument('owner_id')) : null;

        $report = $manager->for($owner ?? $type, $owner ? null : $this->argument('owner_id'))
            ->explain($this->argument('ability'), $this->record($resources));

        // The same text a refusal carries in debug mode, so support reads one format everywhere.
        foreach (explode("\n", app(Explainer::class)->describe($report)) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * @return Model|class-string|null
     */
    private function record(ResourceRegistry $resources): Model|string|null
    {
        $record = $this->argument('record');
        if ($record === null) {
            return null;
        }

        [$alias, $id] = array_pad(explode(':', $record, 2), 2, null);

        $model = $resources->model($alias) ?? throw new InvalidArgumentException('"'.$alias.'" is not listed in config access.resources');

        return $id === null ? $model : $model::query()->findOrFail($id);
    }
}
