<?php
namespace Wnikk\LaravelAccessRules\Models;

use Wnikk\LaravelAccessRules\Contracts\{
    Role as RoleContract,
    Inheritance as InheritanceContract,
    Linkage as LinkageContract,
    Owners as OwnersContract
};

class Helper
{
    /**
     * @var RoleContract
     */
    protected $role;

    /**
     * @var InheritanceContract
     */
    protected $inheritance;

    /**
     * @var LinkageContract
     */
    protected $linkage;

    /**
     * @var OwnersContract
     */
    protected $owners;

    /**
     * __construct
     */
    public function __construct()
    {
        $this->role = app(RoleContract::class);
        $this->inheritance = app(InheritanceContract::class);
        $this->linkage = app(LinkageContract::class);
        $this->owners  = app(OwnersContract::class);
    }

    /**
     * Returns all owners with inheritance
     *
     * @param $ownersId
     * @return array<int>
     */
    protected function getAllParentOwners($ownersId): array
    {
        // At your leisure, replace to "WITH RECURSIVE "
        $listIds  = $this->inheritance->where('owner_id', $ownersId)->pluck('owner_id_parent')->toArray();
        $checkIds = $listIds;
        $listIds  = array_flip( $listIds );
        $limit    = 1000;
        while( count($checkIds) && $limit-- ){
            $tmp = $this->inheritance->where('owner_id', array_shift($checkIds))->pluck('owner_id_parent')->toArray();
            foreach( $tmp as $id )
            {
                if (isset($listIds[$id])) continue;
                $checkIds[] = $id;
                $listIds[$id] = $id;
            }
        }
        return array_keys( $listIds );
    }

    /**
     * @param array $list
     * @return array
     */
    private function dbRolesToArray(array $list): array
    {
        $roles = [];
        foreach ($list as $item) {
            $key = $item['role_id'].($item['option']?'.'.$item['option']:null);
            $roles[$key] = $item;
        }
        return $roles;
    }

    /**
     * We get all the allowed rights for the specified user,
     * taking into account inheritance.
     *
     * @param int $type
     * @param int $originalId
     * @return array<array{role_id:int, option:string|null}>
     */
    public function getAllRoleIDs(int $type, int $originalId = null): array
    {
        $owner = $this->owners
            ->where('type', $type)
            ->where('original_id', $originalId)
            ->first();

        if (!$owner) return [];

        $parentIds = $this->getAllParentOwners($owner->id);

        $parentIds[] = $owner->id;

        $allow    = $this->linkage->whereIn('owner_id', $parentIds)->where('permission', 1)->get(['role_id', 'option'])->toArray();
        $disallow = $this->linkage->whereIn('owner_id', $parentIds)->where('permission', 0)->get(['role_id', 'option'])->toArray();
        $allow    = $this->dbRolesToArray($allow);
        $disallow = $this->dbRolesToArray($disallow);

        $allow = array_diff_key($allow, $disallow);

        $personally = $this->linkage->where('owner_id', $owner->id)->where('permission', 1)->get(['role_id', 'option'])->toArray();
        $personally = $this->dbRolesToArray($personally);

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
     * @return array
     */
    public function getAllPermittedRole(int $type, int $originalId = null): array
    {
        $allow = $this->getAllRoleIDs($type, $originalId);
        $roleIds = [];
        foreach ($allow as $item) $roleIds[] = $item['role_id'];

        $roles = $this->role->find($roleIds, ['id', 'guard_name'])->pluck('guard_name', 'id')->toArray();

        foreach ($allow as $k => &$item) {
            if (empty($roles[$item['role_id']])) {
                unset($item[$k]);
                continue;
            }
            $item = $roles[$item['role_id']].($item['option']?'.'.$item['option']:null);
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
    public function filterPermission(array $PermittedList, $permission)
    {
        return in_array($permission, $PermittedList);
    }
}
