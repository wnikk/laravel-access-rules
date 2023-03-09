<?php
namespace Wnikk\LaravelAccessRules\Helper;

use LogicException;

trait AccessRulesTypeOwner
{
    /**
     * @param int $type
     * @return void
     */
    protected static function checkTypeID(int $type)
    {
        $ownerTypes = self::getListTypes();
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
    public static function getTypeID($type): int
    {
        if (is_numeric($type)) {
            self::checkTypeID((int)$type);
            return (int)$type;
        }

        $ownerTypes = self::getListTypes();
        $realType   = array_search($type, $ownerTypes, true);
        if ($realType === false) {
            throw new LogicException('Error: config/access.php not find on owner_types class "'.$type.'".');
        }
        return (int)$realType;
    }

    /**
     * Return list of owner types
     *
     * @return array<int, string>
     */
    public static function getListTypes()
    {
        return config('access.owner_types');
    }
}
