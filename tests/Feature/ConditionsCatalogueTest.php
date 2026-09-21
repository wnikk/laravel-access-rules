<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;

/**
 * A catalogue of typical conditions, each checked two ways that must agree.
 *
 * These are the rules that roles cannot express, even with an option: the answer depends on
 * data of the record, of related records, of the user or of the moment. For each of them
 * three answers have to be the same:
 *   1. the ids written here by hand,
 *   2. the list filtered by Model::query()->allowedTo(), in exactly one query,
 *   3. every loaded record asked through $user->can(), which goes through Laravel Gate.
 *
 * The second and third come from different code, the SQL compiler and the evaluator. A list
 * that shows a record the detail page refuses is the failure this package exists to prevent,
 * so every new kind of condition gets a line in scenarios() before it gets an implementation.
 *
 * Expected ids are literal on purpose. Computing them with the same library would make the
 * test agree with whatever the library does.
 */
class ConditionsCatalogueTest extends FeatureTestCase
{
    /** @var TestUser */
    protected $user;

    /**
     * Time stands still at 2026-09-18 12:00, a Friday, because scenarios say "last 24 hours",
     * "last month" and "on working days". The user is number 7 of department 1 and inherits
     * teams 1 and 2; ShopSchema::seed() describes the data from that user's point of view.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-18 12:00:00');   // Friday

        Config::set('access.owner_types', [TestUser::class, 'Team', 'Group']);
        Config::set('access.tenant_types', ['Team']);

        ShopSchema::create();
        ShopSchema::seed();

        $this->user = TestUser::factory()->make()->forceFill([
            'id' => 7, 'department_id' => 1, 'level' => 3, 'approval_limit' => 500, 'prefix' => 're',
        ]);

        foreach ([1, 2] as $team) {
            $this->getAccessRules()->newOwner('Team', $team, 'Team '.$team);
            $this->user->inheritPermissionFrom('Team', $team);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function scenarios(): array
    {
        return [
            // columns of the record and attributes of the user
            'ownership written as a condition'       => ['order', 'order.testuser_id == user.id', [1, 2, 3]],
            'ownership by built-in isAuthor()'       => ['order', 'isAuthor()', [1, 2, 3]],
            'threshold + count of related records'   => ['order', 'order.cost > 100 && order.items.count < 3', [1, 4, 5, 6]],
            'same department as the user'            => ['order', 'order.department_id == user.department_id', [1, 2, 4, 6]],
            'state machine'                          => ['order', "order.status in ['draft', 'review'] and not order.locked", [1, 2, 5, 6]],
            'only records of the last 24 hours'      => ['order', "order.created_at >= ago('24 hours')", [1, 3, 4, 6]],
            'clearance level'                        => ['order', 'order.min_level <= user.level', [1, 3, 4, 5, 6]],
            'column to column'                       => ['order', 'order.cost <= order.budget', [1, 3, 4]],
            'approval limit + segregation of duties' => ['order', 'order.cost <= user.approval_limit && order.testuser_id != user.id', [4, 5]],
            'root "resource" instead of the alias'   => ['order', 'resource.cost > 100 && not resource.locked', [1, 2, 5, 6]],

            // to-one relation
            'attribute of a related record'       => ['order', "order.client.city != 'Y'", [1, 2, 4]],
            'negation keeps three-valued logic'   => ['order', "!(order.client.city == 'Y')", [1, 2, 4]],
            'explicit work with NULL'             => ['order', "order.client.city == null || order.client.city != 'Y'", [1, 2, 4, 5, 6]],
            'orders of clients I manage'          => ['order', 'order.client.manager_id == user.id', [1, 2, 3, 5]],
            'teams of the user (tenant)'          => ['order', 'order.client.team_id in user.tenant', [1, 2, 3, 5]],
            'two hops: head office of the client' => ['order', "order.client.parent.city == 'X'", [3, 4]],

            // environment
            'weekday folds into a constant'       => ['order', 'env.weekday in [1,2,3,4,5] && order.cost < 200', [1, 2, 3, 5]],
            'environment makes the rule false'    => ['order', 'env.weekday in [6,7] && order.cost < 200', []],
            'environment alone allows everything' => ['order', "env.ip in ['127.0.0.1'] || order.cost < 0", [1, 2, 3, 4, 5, 6]],

            // one to many
            'aggregate through to-one then to-many' => ['order', 'count(order.client.payments) >= 2', [1, 2, 4]],
            'exists with a filter'                  => ['client', "exists(client.orders, status == 'draft' && cost > 100)", [1, 3, 4]],
            'no related records at all'             => ['client', 'not exists(client.orders)', []],
            'turnover of the last month'            => ['client', "sum(client.payments.amount, paid_at >= monthStart(-1) && paid_at < monthStart(0)) > 1000 && client.city != 'Y'", [1]],
            'max over a relation'                   => ['client', 'max(client.payments.amount) >= 2000', [1, 2]],
            'filter refers to the user'             => ['client', 'exists(client.orders, testuser_id == user.id && cost >= 150)', [1]],

            // many to many (pivot table)
            'no restricted products in the order' => ['order', 'not exists(order.products, restricted)', [1, 3, 5, 6]],
            'count through a pivot table'         => ['order', 'count(order.products) >= 2', [1, 4]],
            'category of products of the order'   => ['order', "exists(order.products, category in ['food'])", [1, 4, 6]],

            // columns of the pivot table
            'pivot column in a filter'            => ['order', 'exists(order.products, pivot.quantity > 4)', [2, 4]],
            'pivot column next to a plain column' => ['order', "count(order.products, pivot.quantity >= 4 && category != 'weapon') >= 1", [1, 5]],
            'sum of a pivot column'               => ['order', 'sum(order.products.pivot.quantity) >= 6', [1, 2, 4]],

            // arithmetic
            'margin: float trap at exactly 600'    => ['order', 'order.cost * 1.2 > order.budget', [2, 5]],
            'difference of two columns'            => ['order', 'order.budget - order.cost >= 50', [1, 3, 4]],
            'division is not integer division'     => ['order', 'order.cost / 4 > 37', [1, 2, 4, 6]],
            'division by zero is unknown'          => ['order', 'order.cost / order.items.count > 100', [4, 6]],
            'and stays unknown under not'          => ['order', 'not (order.cost / order.items.count > 100)', [1, 2, 3]],
            'arithmetic over the user folds'       => ['order', 'order.cost <= user.approval_limit * 0.5', [1, 2, 3, 5]],
            'unary minus and brackets'             => ['order', '-(order.cost - 100) < -300', [4, 6]],
            'brackets on the right survive print'  => ['order', 'order.budget - (order.cost - 50) > 0', [1, 3, 4]],
            'a value that is no number is unknown' => ['order', 'order.cost * user.prefix > 0 || order.cost * user.prefix <= 0', []],
            'precedence: multiplication first'     => ['order', 'order.cost + order.budget * 2 > 1000', [4]],
            'arithmetic against a value of a user' => ['order', 'order.cost * 2 <= user.approval_limit', [1, 2, 3, 5]],

            // between
            'between includes both ends' => ['order', 'order.cost between 101 and 150', [1, 2, 5]],
            'not between'                => ['order', 'order.cost not between 101 and 150', [3, 4, 6]],
            'between of dates'           => ['order', "order.created_at between '2026-09-17 00:00:00' and '2026-09-18 09:00:00'", [1, 4, 6]],

            // text
            'starts with'                        => ['order', "startsWith(order.status, 'dr')", [1, 4, 5]],
            'ends with'                          => ['order', "endsWith(order.status, 'ed')", [3]],
            'contains'                           => ['order', "contains(order.status, 'vi')", [2, 6]],
            'text functions are exact'           => ['order', "startsWith(order.status, 'DR')", []],
            'prefix of the user'                 => ['order', 'startsWith(order.status, user.prefix)', [2, 6]],
            'text of a related record'           => ['order', "contains(order.client.city, 'X')", [1, 2, 4]],
            'NULL stays unknown under not'       => ['order', "not contains(order.client.city, 'X')", [3]],
            'lower() to ignore case'             => ['order', "lower(order.client.city) == 'x'", [1, 2, 4]],
            'lower() inside a text function'     => ['order', "startsWith(lower(order.client.city), 'x')", [1, 2, 4]],
            'everything contains the empty text' => ['order', "contains(order.client.city, '')", [1, 2, 3, 4]],

            // polymorphic relations (morph map)
            'morphMany: no claims to the order'      => ['order', "not exists(order.comments, kind == 'claim')", [1, 3, 5, 6]],
            'morphMany: count'                       => ['order', 'order.comments.count == 0', [3, 5]],
            'morphMany: filter refers to the user'   => ['order', 'exists(order.comments, author_id == user.id)', [1, 2, 4]],
            'morphMany of another type is not mixed' => ['client', 'exists(client.comments)', [2]],
            'morphOne'                               => ['order', "order.lastComment.kind == 'note'", [1, 2, 6]],

            // relation of a table to itself, relation through another model
            'self relation, to-one'     => ['client', "client.parent.city == 'X'", [2, 3]],
            'self relation, to-many'    => ['client', 'count(client.branches) >= 2', [1]],
            'has many through'          => ['client', 'count(client.items) > 3', [1]],
            'sum through another model' => ['client', 'sum(client.items.price) >= 100', [1]],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_filter_of_list_and_check_of_record_agree(string $resource, string $condition, array $expected): void
    {
        $this->getAccessRules()->newRule('shop.see', 'See records', resource: $resource);
        $this->user->addPermission('shop.see', when: $condition);

        $this->assertSame($expected, $this->idsByQuery($resource, 'shop.see'), 'filter of list: '.$condition);
        $this->assertSame($expected, $this->idsByGate($resource, 'shop.see'), 'check of record: '.$condition);
    }

    /**
     * The text of a condition is not stored, only its tree. An admin panel shows what the printer
     * gives, and saving that text unchanged must produce the same tree, or an edit of one
     * condition silently rewrites it.
     */
    #[DataProvider('scenarios')]
    public function test_stored_condition_can_be_printed_and_saved_again_unchanged(string $resource, string $condition, array $expected): void
    {
        $this->getAccessRules()->newRule('shop.see', 'See records', resource: $resource);
        $this->user->addPermission('shop.see', when: $condition);

        // What an editor of an admin panel does: show the text of what is stored, save it back.
        $stored = $this->user->getOwner()->permission()->first()->condition;
        $text   = $this->conditionText($stored, $resource);

        $this->user->remPermission('shop.see');
        $this->user->addPermission('shop.see', when: $text);

        $this->assertSame($stored, $this->user->getOwner()->permission()->first()->condition, 'printed as: '.$text);
        $this->assertSame($expected, $this->idsByQuery($resource, 'shop.see'), 'and it still selects the same records');
    }

    /**
     * The four steps of priority, each added on top of the previous one, in the list and in the check.
     *
     * The permit sits on a role and the restrictions on the user. That is the case prohibitions
     * exist for: the role is shared, and narrowing its condition would narrow it for everybody.
     */
    public function test_priority_of_permissions_in_list_and_in_check(): void
    {
        $this->getAccessRules()->newRule('clients.view', 'View clients', resource: 'client');

        $sales = $this->getAccessRules();
        $sales->newOwner('Group', 'sales', 'Sales');
        $sales->addPermission('clients.view', when: 'sum(client.payments.amount, paid_at >= monthStart(-1) && paid_at < monthStart(0)) > 1000');

        $this->user->inheritPermissionFrom($sales);
        $this->assertVisibleClients([1, 2, 4], 'inherited permission: turnover of the last month');

        $sales->addProhibition('clients.view', when: 'client.manager_id != user.id');
        $this->assertVisibleClients([1, 2, 4], 'inherited prohibition: clients of other managers (3 was not visible anyway)');

        $this->user->addPermission('clients.view', when: 'client.id == 3');
        $this->assertVisibleClients([1, 2, 3, 4], 'own permission beats the inherited prohibition');

        $this->user->addProhibition('clients.view', when: "client.city == 'Y'");
        $this->assertVisibleClients([1, 3, 4], 'own prohibition beats everything; city NULL is unknown, so it is not applicable to client 4');

        $this->user->remProhibition('clients.view');
        $this->user->addProhibition('clients.view');
        $this->assertVisibleClients([], 'own prohibition without a condition hides everything');
    }

    private function assertVisibleClients(array $expected, string $message): void
    {
        $this->assertSame($expected, $this->idsByQuery('client', 'clients.view'), 'filter of list: '.$message);
        $this->assertSame($expected, $this->idsByGate('client', 'clients.view'), 'check of record: '.$message);
    }

    /**
     * Counts queries of the list itself. The first check compiles permissions of the user, and
     * those queries would hide a filter that loads rows and checks them one by one.
     *
     * @return list<int>
     */
    private function idsByQuery(string $resource, string $ability): array
    {
        $this->user->hasPermission($ability);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $ids     = ShopSchema::RESOURCES[$resource]::query()->allowedTo($ability, $this->user)->orderBy('id')->pluck('id')->all();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'a list has to be filtered by a single query');

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function idsByGate(string $resource, string $ability): array
    {
        return ShopSchema::RESOURCES[$resource]::query()->orderBy('id')->get()
            ->filter(fn (Model $record) => $this->user->can($ability, $record))
            ->pluck('id')->values()->all();
    }
}
