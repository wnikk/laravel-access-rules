<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Internal\Xacml;

/**
 * Identifiers of XACML 3.0 that the exporter writes and the importer recognises.
 *
 * XACML names everything with long URNs, and their version prefixes do not follow the version
 * of the standard: "first-applicable" of XACML 3.0 still carries "1.0", text functions carry
 * "3.0", the role attribute "2.0". Both directions of the module read this one table,
 * so they agree on them. Everything the package invents sits under "urn:wnikk:access:",
 * which is also how the importer tells its own documents from foreign ones.
 *
 * @internal Not part of the public API, it may change in any release. AGENTS.md lists what an application may rely on.
 */
final class Vocabulary
{
    public const NS = 'urn:oasis:names:tc:xacml:3.0:core:schema:wd-17';

    public const OWN = 'urn:wnikk:access:';

    public const ROOT = self::OWN.'root';

    /**
     * The fifth policy set of an export, after the four tiers: what a rule is called, how rules
     * are grouped, names of owners, who inherits from whom, the warnings of the export. Its
     * target names this id as the action, which no request does, so an engine never evaluates
     * it. Items are AdviceExpressions with this id and a kind, fields are attribute assignments
     * with this id and a field name.
     */
    public const MANIFEST = self::OWN.'manifest';

    /**
     * The four tiers under the root, strongest first. The root combines them with
     * "first-applicable", which is the five-step priority of the package word for word:
     * own prohibition, own permission, inherited prohibition, inherited permission, nothing.
     */
    public const TIERS = [
        'own:prohibitions'       => ['own' => true, 'permit' => false],
        'own:permissions'        => ['own' => true, 'permit' => true],
        'inherited:prohibitions' => ['own' => false, 'permit' => false],
        'inherited:permissions'  => ['own' => false, 'permit' => true],
    ];

    public const SUBJECT = 'urn:oasis:names:tc:xacml:1.0:subject-category:access-subject';

    public const RESOURCE = 'urn:oasis:names:tc:xacml:3.0:attribute-category:resource';

    public const ACTION = 'urn:oasis:names:tc:xacml:3.0:attribute-category:action';

    public const ENVIRONMENT = 'urn:oasis:names:tc:xacml:3.0:attribute-category:environment';

    public const SUBJECT_ID = 'urn:oasis:names:tc:xacml:1.0:subject:subject-id';

    /** Type of the owner, "App\Models\User" or "Role". The standard has no attribute for it, and an id alone does not name an owner. */
    public const SUBJECT_TYPE = self::OWN.'subject:type';

    /** Owners the subject inherits from, as "Type:id", the whole chain and not only direct parents. The attribute comes from the RBAC profile. */
    public const SUBJECT_ROLE = 'urn:oasis:names:tc:xacml:2.0:subject:role';

    public const ACTION_ID = 'urn:oasis:names:tc:xacml:1.0:action:action-id';

    /** Key of whoever created the record, for the suffix ".self" and isAuthor(). Which column holds it depends on the model, so the side that supplies attributes maps it. */
    public const RESOURCE_AUTHOR = self::OWN.'resource:author';

    public const FN = 'urn:oasis:names:tc:xacml:1.0:function:';

    public const FN3 = 'urn:oasis:names:tc:xacml:3.0:function:';

    /** A part of a condition that XACML cannot say, kept as text of the package. Only this package can read it back. */
    public const FN_DSL = self::OWN.'function:dsl';

    public const XS = 'http://www.w3.org/2001/XMLSchema#';

    public const ALG_FIRST_APPLICABLE = 'urn:oasis:names:tc:xacml:1.0:policy-combining-algorithm:first-applicable';

    public const ALG_DENY_OVERRIDES = 'urn:oasis:names:tc:xacml:3.0:%s-combining-algorithm:deny-overrides';

    public const ALG_PERMIT_OVERRIDES = 'urn:oasis:names:tc:xacml:3.0:%s-combining-algorithm:permit-overrides';

    /**
     * Combining algorithms of a foreign document, by the last segment of their URN, and what they
     * mean for an import. The package always lets a prohibition beat a permission of the same
     * tier, so only algorithms that do the same convert without a change of meaning.
     *
     *   'deny'    a prohibition wins: converts as it is
     *   'permit'  a permission wins: converts only when the container holds no prohibition
     *   'order'   the order of rules decides: converts when prohibitions come first
     *   false     the container permits by default or needs an engine: does not convert
     */
    public const ALGORITHMS = [
        'deny-overrides'           => 'deny',
        'ordered-deny-overrides'   => 'deny',
        'deny-unless-permit'       => 'deny',
        'permit-overrides'         => 'permit',
        'ordered-permit-overrides' => 'permit',
        'first-applicable'         => 'order',
        'permit-unless-deny'       => false,
        'only-one-applicable'      => false,
    ];

    public const ENVIRONMENT_ATTRIBUTES = [
        'now'   => ['urn:oasis:names:tc:xacml:1.0:environment:current-dateTime', 'dateTime'],
        'today' => ['urn:oasis:names:tc:xacml:1.0:environment:current-date', 'date'],
        'time'  => ['urn:oasis:names:tc:xacml:1.0:environment:current-time', 'time'],
    ];

    /** Comparison of the package => the ending of a typed XACML function, "integer-greater-than". "!=" is absent: XACML writes it as not(equal). */
    public const COMPARISONS = [
        '==' => 'equal',
        '>'  => 'greater-than',
        '>=' => 'greater-than-or-equal',
        '<'  => 'less-than',
        '<=' => 'less-than-or-equal',
    ];

    public const ARITHMETIC = ['+' => 'add', '-' => 'subtract', '*' => 'multiply', '/' => 'divide'];

    /** Text functions put what is looked for first: string-starts-with(prefix, text). */
    public const TEXT = ['startsWith' => 'string-starts-with', 'endsWith' => 'string-ends-with', 'contains' => 'string-contains'];

    /**
     * The last segment of a URN, which is what the importer switches on: the prefix of a function
     * has been "1.0", "2.0" and "3.0" over the years and says nothing about what it does.
     */
    public static function local(string $urn): string
    {
        return substr($urn, (int) strrpos($urn, ':') + 1);
    }

    /**
     * @return string "Type:id", the value of the role attribute and the tail of policy ids. A class name has no colon, so the first one separates.
     */
    public static function ownerKey(string $type, string|int|null $id): string
    {
        return $type.':'.$id;
    }
}
