<?php

namespace Wnikk\LaravelAccessRules\Models;

use Wnikk\LaravelAccessRules\Helper\AccessRulesTypeOwner;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Contracts\Permission as PermissionContract;
use Wnikk\LaravelAccessRules\Contracts\Inheritance as InheritanceContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $type
 * @property int $original_id
 * @property string $name
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class Owner extends Model implements OwnerContract
{
    use AccessRulesTypeOwner;

    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'type',
        'original_id',
        'name',
        'created_at',
    ];

    /**
     * @inherited
     */
    protected $guarded = [];

    /**
     * We get specified user
     *
     * @param int $type
     * @param int $originalId
     * @return mixed
     */
    public static function findOwner(int $type, $originalId = null)
    {
        return static::where('type', $type)
            ->where('original_id', $originalId)
            ->first();
    }

    /**
     * Add a permission to owner
     *
     * @param RuleContract $rule
     * @param $option
     * @param bool $access
     * @return bool
     */
    public function addPermission(RuleContract $rule, $option = null, bool $access = true): bool
    {
        $per = app(PermissionContract::class);
        $perData = [
            'owner_id'   => $this->getKey(),
            'rule_id'    => $rule->getKey(),
            'permission' => $access,
            'option'     => $option,
        ];

        $check = $per->where(
            array_map(function($key, $value){
                return [$key, $value];
            }, array_keys($perData), $perData
        ))->first();

        if ($check) {
            throw new LogicException(
                'Role "'.$rule->guard_name.'" has already been previously added to the owner.'
            );
        }

        $per = $per::create($perData);

        return $per->save();
    }
    /**
     * Add blocking resolution to owner
     *
     * @param RuleContract $rule
     * @param $option
     * @return bool
     */
    public function addProhibition(RuleContract $rule, $option = null): bool
    {
        return $this->addPermission($rule, $option, false);
    }

    /**
     * Remove resolution from owner
     *
     * @param RuleContract $rule
     * @param $option
     * @param bool $access
     * @return bool
     */
    public function remPermission(RuleContract $rule, $option = null, $access = true): bool
    {
        $deleted =$this->permission()
            ->where('rule_id', $rule->getKey())
            ->where('option', $option)
            ->where('permission', $access)
            ->delete();

        return (bool)$deleted;
    }

    /**
     * Remove blocking resolution from owner
     *
     * @param RuleContract $rule
     * @param $option
     * @return bool
     */
    public function remProhibition(RuleContract $rule, $option = null): bool
    {
        return $this->remPermission($rule, $option, false);
    }

    /**
     * Adds the user to inherit
     * from specified user in parameter
     *
     * @param Owner $parent
     * @return bool
     */
    public function addInheritance(OwnerContract $parent): bool
    {
        $check = $this->inheritance()
            ->where('owner_parent_id', $parent->getKey())
            ->first();
        if ($check) return true;

        $add = app(InheritanceContract::class);
        $add->owner_id = $this->getKey();
        $add->owner_parent_id = $parent->getKey();
        return $add->save();
    }

    /**
     * Removes user from inheritance
     * from specified user in parameter
     *
     * @param Owner $parent
     * @return int
     */
    public function remInheritance(OwnerContract $parent)
    {
        return $this->inheritance()
            ->where('owner_parent_id', $parent->getKey())
            ->delete();
    }

    /**
     * @inherited
     */
    public function getTable()
    {
        return config('access.table_names.owner', parent::getTable());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function permission(): HasMany
    {
        return $this->hasMany(Permission::class, 'owner_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function inheritance(): HasMany
    {
        return $this->hasMany(Inheritance::class, 'owner_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function inheritanceParent(): HasMany
    {
        return $this->hasMany(Inheritance::class, 'owner_parent_id');
    }
}
