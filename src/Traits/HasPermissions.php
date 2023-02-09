<?php
namespace Wnikk\LaravelAccessRules\Traits;

use Wnikk\LaravelAccessRules\AccessRules;

trait HasPermissions
{
    /** @var AccessRules */
    private $arClass;

    /**
     * Determine if the model may perform the given permission.
     *
     */
    public function hasPermissions($permission): bool
    {
        if (!$this->arClass){
            $this->arClass = app(AccessRules::class);
            $this->arClass->setOwner($this);
        }
        return $this->arClass->hasPermission($permission);
    }
}
