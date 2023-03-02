<?php
namespace Wnikk\LaravelAccessRules\Helper;

use LogicException;

trait AccessRulesTypeOwner
{
    /**
     * @param int $type
     * @return void
     */
    protected function checkTypeID(int $type)
    {
        $ownerTypes  = config('access.owner_types');
        if (empty($ownerTypes[$type])) {
            throw new LogicException('Error: config/access.php not find on owner_types id #'.$type.'.');
        }
    }

    /**
     * Check that this type is in the config, get id by name
     *
     * @param int|string $type
     * @return int
     */
    public function getTypeID($type): int
    {
        if (is_numeric($type)) {
            $this->checkTypeID((int)$type);
            return (int)$type;
        }

        $ownerTypes  = config('access.owner_types');
        $realType    = array_search($type, $ownerTypes, true);
        if ($realType === false) {
            throw new LogicException('Error: config/access.php not find on owner_types class "'.$type.'".');
        }
        return (int)$realType;
    }
}
