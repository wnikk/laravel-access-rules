<?php

namespace Wnikk\LaravelAccessRules\Models;

use Wnikk\LaravelAccessRules\Contracts\Owners as OwnersContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $type
 * @property int $original_id
 * @property string $name
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class Owners extends Model implements OwnersContract
{
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
    public function findOwner(int $type, int $originalId = null)
    {
        return $this->owners
            ->where('type', $type)
            ->where('original_id', $originalId)
            ->first();
    }

    /**
     * Adds the user to inherit
     * from specified user in parameter
     *
     * @param Owners $parent
     * @return bool
     */
    public function AddInheritance(OwnersContract $parent): bool
    {
        $check = $this->hasMany('inheritance')
            ->where('owner_id_parent', $parent->getKey())
            ->first();
        if ($check) return true;

        $add = new Inheritance;
        $add->owner_id = $this->getKey();
        $add->owner_id_parent = $parent->getKey();
        return $add->save();
    }

    /**
     * Removes user from inheritance
     * from specified user in parameter
     *
     * @param Owners $parent
     * @return int
     */
    public function RemoveInheritance(OwnersContract $parent)
    {
        return $this->hasMany('inheritance')
            ->where('owner_id_parent', $parent->getKey())
            ->delete();
    }

    /**
     * @inherited
     */
    public function getTable()
    {
        return config('access.table_names.owners', parent::getTable());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function linkage(): HasMany
    {
        return $this->hasMany(Linkage::class, 'owner_id');
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
        return $this->hasMany(Inheritance::class, 'owner_id_parent');
    }
}
