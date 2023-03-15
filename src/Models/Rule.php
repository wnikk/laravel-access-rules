<?php

namespace Wnikk\LaravelAccessRules\Models;

use Wnikk\LaravelAccessRules\Casts\PermissionOption;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $parent_id
 * @property string $guard_name
 * @property string $options
 * @property string $title
 * @property string $description
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Rule extends Model implements RuleContract
{
    use SoftDeletes;

    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'parent_id',
        'guard_name',
        'options',
        'title',
        'description',
        'deleted_at',
    ];

    /**
     * @inherited
     */
    protected $guarded = [];


    /**
     * Find a rule by name
     *
     * @param string $ability
     * @param $option
     * @return RuleContract|null
     */
    public static function findRule(string $ability, &$option = null)
    {
        $rule = static::firstWhere('guard_name', $ability);

        if ($rule) return $rule;
        if (!$n = strrpos($ability, '.')) return null;

        $option  = substr($ability, $n+1);
        $ability = substr($ability, 0, $n);

        $rule = static::where('guard_name', $ability)->first();
        if (!$rule) return null;

        if ($rule->options) {
            $option = app(PermissionOption::class)->set($rule, 'option', $option, []);
        }

        return $rule;
    }

    /**
     * @inherited
     */
    public function getTable()
    {
        return config('access.table_names.rule', parent::getTable());
    }

    /**
     * @return $this
     */
    public function rule()
    {
        return $this;
    }

    /**
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Rule::class, 'parent_id');
    }

    /**
     * @return HasMany
     */
    public function children(): HasMany
    {
        return $this->hasMany(Rule::class, 'parent_id');
    }

    /**
     * @return HasMany
     */
    public function permission(): HasMany
    {
        return $this->hasMany(Permission::class, 'rule_id');
    }
}
