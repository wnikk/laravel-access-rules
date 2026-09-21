<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Models;

/**
 * Where a rule comes from, which decides what an admin panel may do with it.
 *
 * A rule is a name that code asks about: $user->can('orders.view'). Deleting such a rule in
 * an admin panel silently turns a check off, and a rule that no code asks about does nothing.
 * So rules written together with code are created and removed by migrations, and a panel may
 * only reword them and edit their options. Code that builds names at run time,
 * can('news.edit.'.$category->slug), needs rules a panel can create and delete freely.
 *
 * The column is text and not a boolean "locked", because there are three sources already: the
 * XACML import creates rules that neither code nor an administrator has named. Stored values
 * are part of the data of every project, so cases are added and never renamed.
 */
enum RuleOrigin: string
{
    /** Created by a migration or a seeder together with the code that checks it. The default. */
    case Code = 'code';

    /** Created by an administrator, for abilities that code names at run time. */
    case Custom = 'custom';

    /** Created by an import because a foreign document named it. Nobody has confirmed that code checks it. */
    case Import = 'import';

    /**
     * Whether RuleCatalog::discard() and the structural fields of RuleCatalog::edit() are open.
     */
    public function isManagedByCode(): bool
    {
        return $this === self::Code;
    }
}
