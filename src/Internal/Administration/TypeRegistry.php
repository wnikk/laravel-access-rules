<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Internal\Administration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;

/**
 * Translates names from config access.owner_types into the numbers that owner records carry.
 *
 * A record of an owner stores its type as an integer, because the type is part of the index
 * every lookup goes through and a class name of sixty characters makes that index wide. The
 * number is CRC-16 of the name. It does not depend on the position in the list, so inserting a
 * type in the middle of the list does not re-point existing records, and it is the number
 * version 2 wrote, so its data works without conversion.
 *
 * The price is that a renamed class gets a new number and loses its records. A lookup table in
 * the database would survive a rename, but it would put a query, or one more cache, on the path
 * of every check. Owner classes are User and Client, and projects do not rename them.
 *
 * The administration layer owns this class. Administration\Owners and Authorization\GateHook
 * call it on every check, which is why it holds the config repository instead of calling the
 * config() helper: the helper costs 0.9 microseconds, the repository 0.3.
 *
 * @internal Not part of the public API, it may change in any release. AGENTS.md lists what an application may rely on.
 */
final class TypeRegistry
{
    /**
     * The config value the maps were built from. sync() compares it with the current one, so a
     * test or a tenant switch that replaces the list at runtime gets new maps without a restart.
     */
    private ?array $source = null;

    /** @var array<string, int> */
    private array $ids = [];

    /** @var array<int, string> */
    private array $names = [];

    public function __construct(private Repository $config) {}

    /**
     * @param int|string $type Class, pseudonym like "Role", or a number that is already an id. Console arguments arrive as strings, hence the digit check.
     *
     * @throws AccessRulesException With code UNKNOWN_OWNER_TYPE. A type missing from config is a setup mistake, and a silent null would turn it into "no permissions".
     */
    public function id(int|string $type): int
    {
        $this->sync();

        if (is_int($type) || ctype_digit($type)) {
            $type = (int) $type;
            if (! isset($this->names[$type])) {
                throw new AccessRulesException('Error: config/access.php not find on owner_types id #'.$type.'.', AccessRulesException::UNKNOWN_OWNER_TYPE);
            }

            return $type;
        }

        return $this->ids[$type]
            ?? throw new AccessRulesException('Error: config/access.php not find on owner_types class "'.$type.'".', AccessRulesException::UNKNOWN_OWNER_TYPE);
    }

    /**
     * Returns null instead of throwing, unlike id(). Laravel Gate calls this for every
     * authenticated model, and an Admin model that is not an owner must fall through to regular
     * policies, not break the request.
     *
     * Parent classes count: a project that extends its User model for a guard keeps its permissions.
     * The answer for a subclass is stored back into the map, so class_parents() runs once per class.
     */
    public function idOfModel(Model $model): ?int
    {
        $this->sync();

        $class = $model::class;
        if (isset($this->ids[$class])) {
            return $this->ids[$class];
        }

        foreach (class_parents($model) as $parent) {
            if (isset($this->ids[$parent])) {
                return $this->ids[$class] = $this->ids[$parent];
            }
        }

        return null;
    }

    public function name(int $id): ?string
    {
        $this->sync();

        return $this->names[$id] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        $this->sync();

        return $this->names;
    }

    private function sync(): void
    {
        // An array comparison on every call is the price of following config at runtime. For a list
        // of three names it is cheaper than any invalidation hook, and Laravel config has none.
        $source = $this->config->get('access.owner_types') ?? [];
        if ($source === $this->source) {
            return;
        }

        $this->source = $source;
        $this->ids    = $this->names = [];

        foreach ($source as $name) {
            $id = self::crc16((string) $name);

            // Two names with one number would share permissions without a word. The chance is one in
            // 65536 per pair, and the check runs only here, when the list changes, never on a check.
            if (isset($this->names[$id])) {
                throw new AccessRulesException('Error: config/access.php owner_types "'.$this->names[$id].'" and "'.$name.'" get the same id #'.$id.'.', AccessRulesException::UNKNOWN_OWNER_TYPE);
            }

            $this->ids[(string) $name] = $id;
            $this->names[$id]          = (string) $name;
        }
    }

    /**
     * CRC-16/CCITT-FALSE, written out because PHP ships only crc32(). The algorithm must stay
     * bit for bit what version 2 used: a different polynomial re-numbers every owner in the database.
     */
    public static function crc16(string $data): int
    {
        $crc = 0xFFFF;
        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $x = (($crc >> 8) ^ ord($data[$i])) & 0xFF;
            $x ^= $x >> 4;
            $crc = (($crc << 8) ^ ($x << 12) ^ ($x << 5) ^ $x) & 0xFFFF;
        }

        return $crc;
    }
}
