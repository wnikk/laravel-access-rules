<?php
namespace Wnikk\LaravelAccessRules\Helper;

use LogicException;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;

trait AccessRulesPermission
{
    /**
     * Create a rule
     *
     * @param mixed $guardName
     * @param string|null $title
     * @param string|null $description
     * @param int|null $parentRuleID
     * @param mixed $options
     * @return int|false
     */
    public static function newRule($guardName, string $title = null, string $description = null, int $parentRuleID = null, $options = null)
    {
        if (is_array($guardName)) {
            $title        = $guardName['title']??null;
            $description  = $guardName['description']??null;
            $parentRuleID = $guardName['parent_id']??null;
            $options      = $guardName['options']??null;
            $guardName    = $guardName['guard_name']??null;
        }

        $rule = self::getRuleModel();

        $rule->guard_name  = $guardName;
        $rule->title       = $title;
        $rule->description = $description;
        $rule->options     = $options;
        if ($parentRuleID) {
            $parent = self::getRuleModel();
            $rule->parent_id = $parent::findOrFail($parentRuleID)->id;
        }

        return $rule->save()?$rule->id:false;
    }

    /**
     * Remove rule
     *
     * @param string $guardName
     * @return mixed
     */
    public static function delRule(string $guardName, bool $force = false)
    {
        $rule = self::getRuleModel()
            ->where('guard_name', $guardName)
            ->first();

        if (!$rule) {return false;}

        if ($force) {
            return $rule->forceDelete();
        }

        return $rule->delete();
    }

    /**
     * Cleans cache permission when they change
     *
     * Call it after the changes are written to the database:
     * cache flushed before the write can be refilled with old permissions
     * by a concurrent request and stay outdated until expiration.
     *
     * @return void
     */
    public function refreshPermission()
    {
        $owner = $this->getOwner();
        if (!$owner) {return;}

        /**
         * @var AccessRulesCache $this
         */
        if (!method_exists($this, 'forgetSelectedCachePermission')) {return;}

        list($type, $id) = $this->getOwnerMarker();
        $withChildren    = (bool)$owner->inheritanceParent()->count();

        $this->flushPermissionCache($type, $id, $withChildren);

        // Until commit other requests still read old permissions and may cache them again
        try {
            $connection = $owner->getConnection();
            if ($connection->transactionLevel() > 0 && method_exists($connection, 'afterCommit')) {
                $connection->afterCommit(function () use ($type, $id, $withChildren) {
                    $this->flushPermissionCache($type, $id, $withChildren);
                });
            }
        } catch (\Throwable $e) {
            // Transactions manager is not available, cache has already been flushed above
        }
    }

    /**
     * Flush cached permissions of owner, or all of them when owner has heirs
     *
     * @param int $type
     * @param mixed $id
     * @param bool $withChildren
     * @return void
     */
    protected function flushPermissionCache(int $type, $id, bool $withChildren)
    {
        // Owner of this instance can be switched before deferred call
        list($thisType, $thisId) = $this->getOwnerMarker();
        if ($thisType === $type && $thisId === $id) {
            $this->permissions = null;
        }

        if ($withChildren) {
            $this->clearAllCachedPermissions();
        } else {
            $this->forgetSelectedCachePermission([['type' => $type, 'id' => $id]]);
        }
    }

    /**
     * Add a permission to owner
     *
     * @param $ability
     * @param $option
     * @param bool $access
     * @return bool
     */
    protected function addLinkToRule($ability, $option, $access): bool
    {
        $owner = $this->getOwner();
        $rule  = $this->findRule($ability, $option);

        if (!$owner) {
            throw new LogicException(
                'Owner not find in the database. Before adding a permission, add owner to DB.'
            );
        }
        if (!$rule) {
            throw new LogicException(
                'Rule "'.$ability.'" is absent in the database. Before adding a permission, add rule to DB.'
            );
        }

        $result = $owner->addPermission($rule, $option, $access);

        $this->refreshPermission();

        return $result;
    }

    /**
     * Add blocking resolution to owner
     *
     * @param $ability
     * @param $option
     * @param bool $access
     * @return bool
     */
    protected function remLinkToRule($ability, $option, $access): bool
    {
        $owner = $this->getOwner();
        $rule  = $this->findRule($ability, $option);
        if (!$owner || !$rule) {return false;}

        $result = $owner->remPermission($rule, $option, $access);

        $this->refreshPermission();

        return $result;
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
        return $this->addLinkToRule($ability, $option, true);
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
        return $this->addLinkToRule($ability, $option, false);
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
        return $this->remLinkToRule($ability, $option, true);
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
        return $this->remLinkToRule($ability, $option, false);
    }

}
