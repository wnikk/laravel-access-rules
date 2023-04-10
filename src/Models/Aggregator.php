<?php
namespace Wnikk\LaravelAccessRules\Models;

use Wnikk\LaravelAccessRules\Contracts\{
    Rule as RuleContract,
    Inheritance as InheritanceContract,
    Permission as PermissionContract,
    Owner as OwnerContract
};

class Aggregator
{
    /**
     * @return OwnerContract
     */
    protected static function getOwnerModel()
    {
        return app(OwnerContract::class);
    }

    /**
     * @return RuleContract
     */
    protected static function getRuleModel()
    {
        return app(RuleContract::class);
    }

    /**
     * @return InheritanceContract
     */
    protected static function getInheritanceModel()
    {
        return app(InheritanceContract::class);
    }

    /**
     * @return PermissionContract
     */
    protected static function getPermissionModel()
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
     * @return array<string, array{rule_id:int, option:string|null}>
     */
    private function dbPermToArray(array $list): array
    {
        $rules = [];
        foreach ($list as $item) {
            $key = 'R'.$item['rule_id'].'n'.($item['option']?'.'.$item['option']:null);
            $rules[$key] = $item;
        }
        return $rules;
    }

    /**
     * @param array $list
     * @return array<int, string>
     */
    private function permArrayToRules(array $list): array
    {
        $rule    = $this->getRuleModel();
        $ruleIds = [];
        foreach ($list as $item) $ruleIds[] = $item['rule_id'];

        $rules = $rule->find($ruleIds, ['id', 'guard_name'])->pluck('guard_name', 'id')->toArray();

        foreach ($list as $k => &$item) {
            if (empty($rules[$item['rule_id']])) {
                unset($item[$k]);
                continue;
            }
            $item = $rules[$item['rule_id']].($item['option']?'.'.$item['option']:null);
        } unset($item);

        return array_values($list);
    }

    /**
     * We get all the allowed rights for the specified user,
     * taking into account inheritance.
     *
     * @param int $type
     * @param int $originalId
     * @return array{ allow:array{rule_id:int, option:string|null}, disallow: array{rule_id:int, option:string|null} }
     */
    protected function getAllRuleIDMap(int $type, $originalId = null): array
    {
        $permission = $this->getPermissionModel();
        $owner      = $this->findOwner($type, $originalId);

        if (!$owner) return [
            'allow'    => [],
            'disallow' => [],
        ];

        $parentIds = $this->getAllParentOwnersID($owner->id);

        $parentIds[] = $owner->id;

        $allow    = $permission->whereIn('owner_id', $parentIds)->where('permission', 1)->get()->toArray();
        $disallow = $permission->whereIn('owner_id', $parentIds)->where('permission', 0)->get()->toArray();
        $allow    = $this->dbPermToArray($allow);
        $disallow = $this->dbPermToArray($disallow);

        $allow = array_diff_key($allow, $disallow);

        $personally = $permission->where('owner_id', $owner->id)->where('permission', 1)->get()->toArray();
        $personally = $this->dbPermToArray($personally);

        $allow = array_merge($allow, $personally);

        return [
            'allow'    => array_values($allow),
            'disallow' => array_values($disallow),
        ];
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
        $perms = $this->getAllRuleIDMap($type, $originalId);

        return $this->permArrayToRules($perms['allow']);
    }

    /**
     * Returns the name of valid rules
     *
     * @param int $type
     * @param int $originalId
     * @return array<int, string>
     */
    public function getAllProhibitedRule(int $type, $originalId = null): array
    {
        $perms = $this->getAllRuleIDMap($type, $originalId);

        return $this->permArrayToRules($perms['disallow']);
    }

    /**
     * Find a permission.
     *
     * @param array $PermittedList
     * @param string $permission
     * @return bool|null
     */
    public function filterPermission(array $PermittedList, $permission)
    {
        return in_array($permission, $PermittedList)?true:null;
    }
}
