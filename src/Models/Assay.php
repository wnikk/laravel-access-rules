<?php
namespace Wnikk\LaravelAccessRules\Models;

use Wnikk\LaravelAccessRules\Contracts\{
    Rule as RuleContract,
    Inheritance as InheritanceContract,
    Permission as PermissionContract,
    Owner as OwnerContract
};

class Assay
{
    /**
     * @return OwnerContract
     */
    protected function getOwnerModel()
    {
        return app(OwnerContract::class);
    }

    /**
     * @return RuleContract
     */
    protected function getRuleModel()
    {
        return app(RuleContract::class);
    }

    /**
     * @return InheritanceContract
     */
    protected function getInheritanceModel()
    {
        return app(InheritanceContract::class);
    }

    /**
     * @return PermissionContract
     */
    protected function getPermissionModel()
    {
        return app(PermissionContract::class);
    }

    /**
     * @param int $type
     * @param $originalId
     * @return mixed
     */
    public function findOwner(int $type, $originalId = null)
    {
        $owners = $this->getOwnerModel();
        return $owners::findOwner($type, $originalId);
    }

    /**
     * @param string $ability
     * @param $option
     * @return mixed
     */
    public function findRule(string $ability, &$option = null)
    {
        $rule = $this->getRuleModel();
        return $rule::findRule($ability, $option);
    }

    /**
     * Returns all owners with inheritance
     *
     * @param int $ownersId
     * @return array<int, int>
     */
    protected function getAllParentOwnersID(int $ownersId): array
    {
        $inheritance = $this->getInheritanceModel();
        // At your leisure, replace to "WITH RECURSIVE "
        $listIds  = $inheritance->where('owner_id', $ownersId)->pluck('owner_parent_id')->toArray();
        $checkIds = $listIds;
        $listIds  = array_flip($listIds);
        $limit    = 100;
        while( count($checkIds) && --$limit ){
            $tmp = $inheritance->whereIn('owner_id', $checkIds)->pluck('owner_parent_id')->toArray();
            $checkIds = [];
            foreach( $tmp as $id )
            {
                if (isset($listIds[$id])) continue;
                $checkIds[] = $id;
                $listIds[$id] = $id;
            }
        }
        return array_keys($listIds);
    }

    /**
     * @param array $list
     * @return array<float, mixed>
     */
    private function dbRulesToArray(array $list): array
    {
        $rules = [];
        foreach ($list as $item) {
            $key = $item['rule_id'].($item['option']?'.'.$item['option']:null);
            $rules[$key] = $item;
        }
        return $rules;
    }

    /**
     * We get all the allowed rights for the specified user,
     * taking into account inheritance.
     *
     * @param int $type
     * @param int $originalId
     * @return array<array{rule_id:int, option:string|null}>
     */
    protected function getAllRuleIDs(int $type, $originalId = null): array
    {
        $permission = $this->getPermissionModel();
        $owner      = $this->findOwner($type, $originalId);

        if (!$owner) return [];

        $parentIds = $this->getAllParentOwnersID($owner->id);

        $parentIds[] = $owner->id;

        $allow    = $permission->whereIn('owner_id', $parentIds)->where('permission', 1)->get(['rule_id', 'option'])->toArray();
        $disallow = $permission->whereIn('owner_id', $parentIds)->where('permission', 0)->get(['rule_id', 'option'])->toArray();
        $allow    = $this->dbRulesToArray($allow);
        $disallow = $this->dbRulesToArray($disallow);

        $allow = array_diff_key($allow, $disallow);

        $personally = $permission->where('owner_id', $owner->id)->where('permission', 1)->get(['rule_id', 'option'])->toArray();
        $personally = $this->dbRulesToArray($personally);

        $allow = array_merge($allow, $personally);

        //return [
        //    'allow'    => array_unique($allow),
        //    'disallow' => array_unique($disallow),
        //];

        return $allow;
    }

    /**
     * Returns the name of valid rules
     *
     * @param int $type
     * @param int $originalId
     * @return array<int, string>
     */
    public function getAllPermittedRule(int $type, $originalId = null): array
    {
        $rule    = app(RuleContract::class);
        $allow   = $this->getAllRuleIDs($type, $originalId);

        $ruleIds = [];
        foreach ($allow as $item) $ruleIds[] = $item['rule_id'];

        $rules = $rule->find($ruleIds, ['id', 'guard_name'])->pluck('guard_name', 'id')->toArray();

        foreach ($allow as $k => &$item) {
            if (empty($rules[$item['rule_id']])) {
                unset($item[$k]);
                continue;
            }
            $item = $rules[$item['rule_id']].($item['option']?'.'.$item['option']:null);
        } unset($item);

        return array_values($allow);
    }

    /**
     * Find a permission.
     *
     * @param array $PermittedList
     * @param string $permission
     * @return bool
     */
    public function filterPermission(array $PermittedList, $permission, $args = null)
    {
        return in_array($permission, $PermittedList);
    }
}
