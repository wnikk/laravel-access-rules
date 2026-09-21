## Laravel Access Rules (wnikk/laravel-access-rules)

Roles, inheritance and permissions (RBAC) plus permissions that depend on data (ABAC), through the standard Laravel Gate. One line of text is both the check of a record and the filter of a list, so the two cannot disagree. After the first check of a request a check runs no queries.

### Conventions

- Check access the Laravel way: `$user->can()`, `Gate::authorize()`, `@@can`, the `can:` middleware, `authorizeResource()`. Do not call the package directly where Gate works.
- A permission that depends on data is a **condition** on the permission, not a Policy class and not a hand-written `where()`. Write a Policy only for logic a condition cannot express.
- Lists are filtered with `Model::allowedTo('ability')` (trait `HasAccessScope`). Never load records and filter them with `can()` in a loop.
- A rule has to exist before it is granted. Rules that code checks by name are created in migrations or seeders, never at runtime.
- Models used in conditions are listed in `config/access.php` under `resources`, and their relation methods declare a return type (`: BelongsTo`). Pivot columns need `->withPivot()`.
- A prohibition takes a permission away. It is not a veto: `Gate::before` of the application, policies and other packages keep their word.
- The cache follows every change by itself. Do not flush it by hand; wrap bulk changes in `Access::batch()`.

@verbatim
<code-snippet name="Rules, roles and permissions" lang="php">
use Wnikk\LaravelAccessRules\Facades\Access;

// in a migration or a seeder; "resource" is the alias of the model from config access.resources
Access::newRule('orders.view', 'View orders', resource: 'order');

Access::for('Role', 'manager')->create('Managers');
Access::for('Role', 'manager')->allow('orders.view', when: 'order.cost > 100 && order.items.count < 3');
Access::for('Role', 'manager')->deny('orders.view', when: 'order.locked');

$user->inheritPermissionFrom('Role', 'manager');       // model with the trait HasPermissions
$user->addPermission('orders.view', when: 'order.department_id == user.department_id');
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Checking one record, a list, and records in general" lang="php">
Gate::authorize('orders.view', $order);              // this record, conditions are evaluated in memory
Order::allowedTo('orders.view')->paginate();         // the same conditions as WHERE, one query
$user->can('orders.view', Order::class);             // menu items, "New" buttons: is there any order the user may see?
$user->can('orders.view');                           // only permissions without a condition count
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="When access is refused and it is not clear why" lang="bash">
php artisan acr:explain "App\Models\User" 7 orders.view order:4
php artisan acr:lint          # stored conditions against models and config as they are now; run in CI and after migrate
</code-snippet>
@endverbatim

Activate the `access-rules-development` skill for the condition language, priority of permissions, tenants, trees, debugging and XACML.
