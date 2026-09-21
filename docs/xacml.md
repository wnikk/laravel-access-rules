---
title: XACML
weight: 5
---

# XACML 3.0: export and import

Permissions of the package can be written as an XACML 3.0 policy, and an XACML policy can be converted into
permissions of the package. The module is for the systems around the application: an audit that wants policies
in a standard form, another service that asks an XACML engine, a backup that does not depend on table layouts,
a migration from a system that kept its policies in XACML.

The package does not run XACML. A document is converted once and becomes ordinary rows, which are checked at
the usual speed. What cannot be converted is reported, not guessed. The module needs the PHP extension `dom`
and is never loaded by a check.

## Export

```bash
php artisan acr:xacml:export storage/app/xacml          # policy.xml and manifest.json in a directory
php artisan acr:xacml:export storage/app/access.zip     # the same two files as one archive
```

The policy is written as it is produced, one owner at a time, so a large export does not need a large memory.

The policy is one flat document. Its root combines four policy sets with `first-applicable`, which is the
priority of the package word for word:

| policy set | holds | addressed to |
|---|---|---|
| `own:prohibitions` | prohibitions of every owner | the subject, by `subject-id` and `urn:wnikk:access:subject:type` |
| `own:permissions` | permissions of every owner | the same |
| `inherited:prohibitions` | the same prohibitions of owners somebody inherits from | everybody whose attribute `urn:oasis:names:tc:xacml:2.0:subject:role` holds `Type:id` of the owner |
| `inherited:permissions` | the same permissions of those owners | the same |

So inheritance travels as the role attribute of the RBAC profile. The side that supplies attributes to the XACML
engine fills it with the whole chain, not only direct parents; `roles` of the manifest lists the chain for every owner.

A rule is the `action-id`: `orders.view`, with an option `orders.export.csv`. The suffix `.self` goes out as the main
ability with the condition "the author of the record is the subject", which reads the attribute
`urn:wnikk:access:resource:author`; map it to the column of the author on the receiving side.

Conditions become typed XACML expressions. Types come from the schema of the database:

| condition | XACML |
|---|---|
| `order.cost > 100` | `integer-greater-than(integer-one-and-only(order:cost), 100)` |
| `order.cost * 1.2 > order.budget` | `double-greater-than(double-multiply(integer-to-double(..), 1.2), ..)` |
| `order.status in ['draft', 'review']` | `string-is-in(.., string-bag('draft', 'review'))` |
| `order.client.team_id in user.tenant` | `integer-is-in(.., designator of urn:wnikk:access:subject:tenant)` |
| `order.client.city == null` | `integer-equal(string-bag-size(order:client.city), 0)` |
| `count(order.items) < 3`, `exists(order.items)` | `anyURI-bag-size` of the attribute `order:items`, a bag of ids of related records |
| `startsWith(order.code, 'A-')`, `lower(..)` | `string-starts-with`, `string-normalize-to-lower-case` |
| `order.testuser_id == user.id` | `integer-equal(.., integer-from-string(subject-id))` |
| `now()`, `env.today` | `current-dateTime`, `current-date` |

Attributes are named `urn:wnikk:access:resource:{alias}:{path}`, `urn:wnikk:access:subject:{path}` and
`urn:wnikk:access:environment:{name}`. Give them names of your own in config, and both directions use them:

```php
'xacml' => [
    'attributes' => [
        'urn:example:order:total' => 'order.cost',
        'urn:example:subject:department' => 'user.department_id',
    ],
],
```

XACML has no words for aggregates with a filter, `sum()`, `min()`, `max()`, tree functions, `ago()`, `monthStart()`
and functions of the application. Such a part of a condition goes out as text inside the function
`urn:wnikk:access:function:dsl`. The package reads it back without loss, another engine cannot run it, and the
export prints a warning for every one of them.

### What differs in XACML

- **NULL.** A comparison with NULL is *unknown* for the package, and the permission is skipped. In XACML the attribute
  is missing and the decision is `Indeterminate`, which an enforcement point turns into a refusal. So an exported
  policy is never wider than the package and is stricter for records with NULL in a compared column.
- **Checks without a record.** `$user->can('orders.view')` and `can('orders.view', Order::class)` are conventions of
  the package. A request to an XACML engine always describes a resource.
- **`rule_tree_inheritance`.** With the option on, a permission for `reports` covers `reports.sales` inside the package,
  and the export names only `reports`. The export warns about it.
- **Laravel policies and `Gate::define()`** live in the application, not in the document. In XACML a `Deny` is a refusal;
  in the application a prohibition takes a permission away, and a policy for the same ability can still permit.

## Import

```bash
php artisan acr:xacml:import storage/app/access.zip --check
php artisan acr:xacml:import storage/app/access.zip
```

The source is a directory of an export, `policy.xml`, an archive, or a foreign XACML document. `manifest.json` is found
inside the archive or next to the policy.

### First look, then import

`--check` writes nothing. It prints a plan: every rule, owner, permission and link of inheritance of the document,
compared with the database as it is now.

| action | means | an import |
|---|---|---|
| `create` | the database does not have it | creates it |
| `same` | both agree (hidden unless `--all`) | does nothing |
| `differs` | both have it, with another title, name or condition; both versions are shown | leaves the database as it is; with `--replace` brings it to the document |
| `only in database` | an export with its manifest lacks it | does nothing: an import never deletes |

The import executes that very plan, so what the check shows is what happens. An export restores everything: rules with
titles, options, their tree and conditions, owners with names, inheritance, every permission with the text of its
condition. A second import of the same files changes nothing.

Any other XACML 3.0 document is a foreign one. Every `Rule` becomes a permission or a prohibition:

- the owner comes from Targets above the rule: `subject-id` (with `--subject-type`, because XACML has no type
  of subject) or the role attribute (`Type:id`, or a bare name with `--role-type`, `Role` by default);
- the ability is the `action-id`; several alternatives give several permissions; a missing rule is created with the
  origin `import`, so an administrator can rename or remove it; rules of an export keep the origin from the manifest;
- other matches of Targets and the `Condition` become the condition. It is compiled like any condition of the package,
  so models have to be listed in `resources` and attributes mapped in `xacml.attributes`;
- `VariableReference` is replaced by its definition; two `Permit` rules of one owner for one action become one permission with `||`;
- a rule without a subject needs `--everyone=Role:users`, the owner everybody inherits from.

What has no counterpart is reported with its address in the document: `permit-unless-deny` and `only-one-applicable`,
`permit-overrides` over prohibitions, `first-applicable` with a Permit before a Deny, obligations and advice,
references to other documents, `AttributeSelector`, regular expressions, integer division, unknown attributes and functions.

**Nothing is written while the plan holds an error.** A prohibition that failed to convert leaves access wider than
its author meant. `--partial` writes what converts anyway; rules of a container whose algorithm does not convert
stay out even then.

One warning deserves reading. Under `deny-overrides` a prohibition of a role beats a permission given to one user.
In the package an own permission is stronger than anything inherited. The document does not say who holds which role,
so the import points at every such pair and leaves the decision to you.

A `DOCTYPE` or an entity declaration is refused before parsing, and the parser never opens the network.

## From code

`Wnikk\LaravelAccessRules\Xacml\Xacml` is the one entry point, and the console commands are thin wrappers over it.
It writes to an open stream or to anything `fopen()` accepts, and reads an uploaded file, a path, a directory,
an archive, a stream or the XML itself.

```php
use Wnikk\LaravelAccessRules\Xacml\Xacml;

// a download
public function download(Xacml $xacml)
{
    return response()->streamDownload(fn () => $xacml->exportArchive('php://output'), 'access-rules.zip');
}

// without the zip extension, or for another engine: the policy alone
return response()->streamDownload(fn () => $xacml->exportPolicy('php://output'), 'policy.xml', ['Content-Type' => 'application/xml']);

// an upload: show the plan first
public function preview(Request $request, Xacml $xacml)
{
    return view('access.import', ['report' => $xacml->check($request->file('policy'), options: ['subject_type' => User::class])]);
}

public function import(Request $request, Xacml $xacml)
{
    $report = $xacml->import($request->file('policy'), options: ['replace' => $request->boolean('replace')]);
}
```

Both `check()` and `import()` return the same report:

```php
[
    'own'      => true,            // an export of this package, or a foreign document
    'errors'   => [[address, text], ...],
    'warnings' => [[address, text], ...],
    'changes'  => [['kind' => 'permission', 'action' => 'differs', 'what' => 'Role:manager may orders.view',
                    'document' => 'order.cost > 100', 'database' => 'order.cost > 500'], ...],
    'summary'  => ['permission' => ['same' => 12, 'differs' => 1], 'rule' => [...], 'owner' => [...], 'inheritance' => [...]],
    'written'  => false,
    'applied'  => ['rules' => 0, 'owners' => 0, 'permissions' => 0, 'inheritance' => 0, 'replaced' => 0],
]
```

An archive needs the PHP extension `zip`; the policy and the manifest are available as two files without it.
An import reads the database whole and runs in one transaction, so put a large one on a queue.
