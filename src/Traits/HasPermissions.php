<?php
namespace Wnikk\LaravelAccessRules\Traits;

use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\AccessRules;

trait HasPermissions
{
    /** @var AccessRules */
    protected $arClass;

    /**
     * Initialize the trait
     *
     * @return void
     */
    protected function initializeHasPermissions()
    {
        $this->arClass = app(AccessRules::class);

        if ($this instanceof Model) {
            $this->arClass->setOwner($this);
        }
    }

    /**
     * Determine if the model may perform the given permission.
     *
     */
    public function hasPermission($permission, $args = null): bool
    {
        return $this->arClass->hasPermission($permission, $args = null);
    }
}
