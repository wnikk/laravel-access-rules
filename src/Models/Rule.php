<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Storage\PermissionCache;

/**
 * Something that can be permitted. Rules form a tree through parent_id, which groups them in
 * admin screens and, with config access.rule_tree_inheritance, passes permissions down.
 *
 * Rules are soft deleted so that a rule removed by mistake returns with all its permissions.
 *
 * @property int         $id
 * @property int         $parent_id
 * @property string      $guard_name
 * @property string|null $options
 * @property string|null $resource
 * @property array|null  $condition
 * @property string|null $title
 * @property string|null $description
 * @property Carbon|null $deleted_at
 */
#[Fillable(['parent_id', 'guard_name', 'options', 'resource', 'condition', 'title', 'description'])]
class Rule extends Model implements RuleContract
{
    use SoftDeletes;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'condition' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Only a real delete touches neighbours. Children move one level up instead of vanishing with
        // their parent, and permissions go by hand because tables of version 2 cascade on some servers only.
        static::deleting(function (self $rule) {
            if (! $rule->isForceDeleting()) {
                return;
            }

            $rule->children()->update(['parent_id' => $rule->parent_id]);
            $rule->permission()->delete();
        });

        // Compiled permissions carry names and conditions of rules. A rule deleted through the model,
        // for example by an admin panel, would otherwise stay permitted until its cache entry expires.
        // The events live on the model because not every change comes through RuleCatalog.
        $flush = static function (self $rule) {
            app(PermissionCache::class)->bump($rule->getConnection());
        };

        static::deleted($flush);
        static::restored($flush);
        static::updated(function (self $rule) use ($flush) {
            if ($rule->wasChanged(['guard_name', 'condition', 'parent_id'])) {
                $flush($rule);
            }
        });
    }

    public function getTable()
    {
        return config('access.table_names.rule', parent::getTable());
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    public function permission(): HasMany
    {
        return $this->hasMany(config('access.models.permission', Permission::class), 'rule_id');
    }
}
