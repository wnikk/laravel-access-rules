<?php
namespace Wnikk\LaravelAccessRules\Helper;

use LogicException;

trait AccessRulesTypeOwner
{
    /**
     * CRC-16 CCITT
     *
     * @param string $data
     * @return int
     */
    protected static function crc16(string $data)
    {
        $crc = 0xFFFF;
        for ($i = 0; $i < strlen($data); $i++)
        {
            $x = (($crc >> 8) ^ ord($data[$i])) & 0xFF;
            $x ^= $x >> 4;
            $crc = (($crc << 8) ^ ($x << 12) ^ ($x << 5) ^ $x) & 0xFFFF;
        }
        return $crc;
    }

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
        $list = [];
        foreach (config('access.owner_types')??[] as $item) {
            $list[self::crc16((string)$item)] = $item;
        }
        return $list;
    }
}
