<?php
namespace Wnikk\LaravelAccessRules\Helper;

use LogicException;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;

trait AccessRulesPermission
{
    /**
     * Create a rule
     *
     * @param string $guardName
     * @param string|null $description
     * @param int|null $parentRuleID
     * @param mixed $options
     * @return int|false
     */
    public function newRule(string $guardName, string $description = null, int $parentRuleID = null, $options = null)
    {
        $rule = $this->getRuleModel();

        $rule->guard_name = $guardName;
        $rule->description = $description;
        $rule->options = $options;
        if ($parentRuleID) $rule->parent_id = $this->getRuleModel()::findOrFail($parentRuleID)->id;

        return $rule->save()?$rule->id:false;
    }

    /**
     * Soft remove rule
     *
     * @param string $guardName
     * @return mixed
     */
    public function delRule(string $guardName)
    {
        return $this->getRuleModel()
            ->where('guard_name', $guardName)
            ->delete();
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

        return $owner->addPermission($rule, $option, $access);
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
        if (!$owner || !$rule) return false;
        return $owner->remPermission($rule, $option, $access);
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
