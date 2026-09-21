<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Events;

/**
 * Tells the application that something about access was written to the database.
 *
 * The package keeps no audit log. What to record, where, and for how long differs from one
 * project to the next, and compliance rules decide it, not a library. This event is the hook:
 * it fires after the write and after cached permissions are dropped, so a listener that
 * checks a permission already sees the new state.
 *
 * One class with an action name, not a class per action. Seven classes with the same two
 * fields would say nothing more, and a listener for an audit log wants all of them anyway.
 */
final class AccessChanged
{
    public const PERMISSION_GRANTED = 'permission.granted';

    public const PERMISSION_REVOKED = 'permission.revoked';

    public const INHERITANCE_ADDED = 'inheritance.added';

    public const INHERITANCE_REMOVED = 'inheritance.removed';

    public const OWNER_DELETED = 'owner.deleted';

    public const RULE_CREATED = 'rule.created';

    public const RULE_DELETED = 'rule.deleted';

    public const RULE_EDITED = 'rule.edited';

    /**
     * @param string               $action  one of the constants of this class
     * @param array<string, mixed> $details what was changed: owner_type, owner_id, rule, option, permit, condition, parent_type, parent_id, force ...
     */
    public function __construct(
        public readonly string $action,
        public readonly array $details = [],
    ) {}
}
