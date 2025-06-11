<?php
namespace Wnikk\LaravelAccessRules\Helper;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;

/**
 * Trait AccessRulesThisOwner
 *
 * This trait provides methods to manage the owner of the access rules,
 * allowing for user and group support.
 *
 */
trait AccessRulesThisOwner
{
    /** @var int */
    protected int $thisOwnerType = -1;

    /** @var mixed|null */
    protected $thisOwnerId;

    /**
     * Set the owner id for user/groups support, this id is used when querying roles
     *
     * @param  int|Model|OwnerContract|Authorizable  $type
     * @param  null|int  $id
     */
    public function setOwner($type, $id = null)
    {
        if ($type instanceof OwnerContract && $type->id)
        {
            $id   = $type->original_id;
            $type = $type->type;
        }
        if ($type instanceof Model)
        {
            if ($id === null) {$id = $type->getKey();}
            $type = get_class($type);
        }
        $type = $this->getTypeID($type);

        $this->thisOwnerType = $type;
        $this->thisOwnerId   = $id;
    }

    /**
     * Return owner by type and id
     *
     * @return null|OwnerContract
     */
    public function getOwner()
    {
        return $this->findOwner($this->thisOwnerType, $this->thisOwnerId);
    }

    /**
     * Create a new owner model if it does not exist
     *
     * @param $type
     * @param $id
     * @param $name
     * @return OwnerContract
     */
    public function newOwner($type, $id = null, $name = null)
    {
        $this->setOwner($type, $id);
        $owner = $this->getOwner();
        if (!$owner) {
            $owner = $this->getOwnerModel();
            $owner->type        = $this->thisOwnerType;
            $owner->original_id = $this->thisOwnerId;
            $owner->name        = $name;
            $owner->save();
        }
        return $owner;
    }

    /**
     * Get the owner model type and original id
     *
     * @return array <int, mixed>
     */
    public function getOwnerMarker()
    {
        return [$this->thisOwnerType, $this->thisOwnerId];
    }
}