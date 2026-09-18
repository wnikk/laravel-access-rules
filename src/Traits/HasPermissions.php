<?php
namespace Wnikk\LaravelAccessRules\Traits;

use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\Contracts\AccessRules as AccessRulesContract;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;

trait HasPermissions
{
    /** @var AccessRulesContract */
    protected $accessRules;

    /** @var array{0:int, 1:mixed}|null Model object id and key (false = not bound yet), that $accessRules belongs to */
    protected $accessRulesBoundTo;

    /** @var string */
    protected $ownerName;

    /**
     * @return AccessRulesContract
     */
    protected function appAccessRulesModel()
    {
        return app(AccessRulesContract::class);
    }

    /**
     * @parent HasEvents
     * @return void
     */
    public static abstract function created($callback);

    /**
     * @parent HasEvents
     * @return void
     */
    public static abstract function deleting($callback);

    /**
     * @parent Model
     * @return mixed
     */
    public abstract function getKey();

    /**
     * Boot the trait
     *
     * Listeners are static: they must be registered once per model class.
     * Registering them per model instance makes every loaded model
     * re-point access rules of all previously loaded models to itself.
     *
     * @return void
     */
    public static function bootHasPermissions()
    {
        static::created(function ($model) {
            $model->getOwner();
        });
        static::deleting(function ($model) {
            $owner = $model->getOwner();
            $owner->delete();
        });
    }

    /**
     * Initialize the trait
     *
     * @return void
     */
    protected function initializeHasPermissions()
    {
        $this->accessRules = $this->appAccessRulesModel();

        // Remember the model that instance was created for, to detect clones sharing it
        $this->accessRulesBoundTo = [spl_object_id($this), false];
    }

    /**
     * Returns access rules bound to this model and only to it
     *
     * @return AccessRulesContract
     */
    protected function ownerAccessRules()
    {
        $bound = [spl_object_id($this), $this->getKey()];

        if ($this->accessRulesBoundTo !== $bound) {
            // Clone of model shares instance with its source, never re-point shared instance
            $shared = $this->accessRulesBoundTo && $this->accessRulesBoundTo[0] !== $bound[0];
            if (!$this->accessRules || $shared) {
                $this->accessRules = $this->appAccessRulesModel();
            }
            $this->accessRules->setOwner($this);
            $this->accessRulesBoundTo = $bound;
        }

        return $this->accessRules;
    }

    /**
     * @return OwnerContract
     */
    public function getOwner()
    {
        $accessRules = $this->ownerAccessRules();

        $owner = $accessRules->getOwner();
        if ($owner) {return $owner;}

        return $accessRules->newOwner(
            $this,
            $this->getKey(),
            $this->ownerName??
            $this->name??
            $this->fullname??
            $this->realname??
            $this->login??
            $this->email??
            $this->phone??
            $this->getKey()??
            null
        );
    }

    /**
     * Get owner object from mixed type
     *
     * @param $type
     * @param $id
     * @return OwnerContract
     */
    private function getOwnerFrom($type, $id = null): OwnerContract
    {
        $owner = null;
        if (is_object($type) && method_exists($type, 'getOwner')) {
            $owner = $type->getOwner();
        } elseif ($type instanceof OwnerContract && $type->id) {
            $owner = $type;
        }

        if (!$owner) {
            $accessRules = $this->appAccessRulesModel();
            $accessRules->setOwner($type, $id);
            $owner = $accessRules->getOwner();
        }
        return $owner;
    }

    /**
     * Adds the user to inherit
     *
     * @param  int|Model|OwnerContract|AccessRulesContract  $type
     * @param  null|int  $id
     */
    public function inheritPermissionFrom($type, $id = null): bool
    {
        $owner  = $this->getOwner();
        $parent = $this->getOwnerFrom($type, $id);

        $this->accessRules->refreshPermission();

        return $parent && $owner->addInheritance($parent);
    }

    /**
     * Remove inherit from parent owner
     *
     * @param  int|Model|OwnerContract|AccessRulesContract  $type
     * @param  null|int  $id
     * @return bool
     */
    public function remInheritFrom($type, $id = null): bool
    {
        $owner  = $this->ownerAccessRules()->getOwner();
        $parent = $this->getOwnerFrom($type, $id);

        $this->accessRules->refreshPermission();

        return $parent && $owner && $owner->remInheritance($parent);
    }


    /**
     * Add a permission to owner
     *
     * @param $ability
     * @param $option
     * @return bool
     */
    public function addPermission($ability, $option = null): bool
    {
        $this->getOwner();
        return $this->accessRules->addPermission($ability, $option);
    }

    /**
     * Add blocking resolution to owner
     *
     * @param $ability
     * @param $option
     * @return bool
     */
    public function addProhibition($ability, $option = null): bool
    {
        $this->getOwner();
        return $this->accessRules->addProhibition($ability, $option);
    }

    /**
     * Remove resolution from owner
     *
     * @param $ability
     * @param $option
     * @return bool
     */
    public function remPermission($ability, $option = null): bool
    {
        $this->getOwner();
        return $this->accessRules->remPermission($ability, $option);
    }

    /**
     * Remove blocking resolution from owner
     *
     * @param $ability
     * @param $option
     * @return bool
     */
    public function remProhibition($ability, $option = null): bool
    {
        $this->getOwner();
        return $this->accessRules->remProhibition($ability, $option);
    }

    /**
     * Determine if the model may perform the given permission.
     *
     * @param string  $ability
     * @param array|null  $args
     * @return bool|null
     */
    public function hasPermission($ability, $args = null): ?bool
    {
        $this->getOwner();
        $check = $this->accessRules->hasPermission($ability, $args);

        // Check magic permission {rule}.self
        if (!$check && $args){
            $check = $this->accessRules->checkMagicRuleSelf($this, $ability, $args);
        }

        return $check;
    }
}
