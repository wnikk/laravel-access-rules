<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Facades\Access;
use Wnikk\LaravelAccessRules\Xacml\Xacml;

/**
 * A document written by somebody else: what converts, what does not, and that nothing is
 * written while something does not.
 *
 * The documents here are small on purpose. Each shows one construct the way a policy author
 * writes it by hand, with attribute names of their own, and the test says what the package
 * makes of it. The refusals matter most: a prohibition that silently fails to convert leaves
 * access wider than its author meant.
 */
class XacmlForeignDocumentsTest extends FeatureTestCase
{
    private const HEAD = '<PolicySet xmlns="urn:oasis:names:tc:xacml:3.0:core:schema:wd-17" PolicySetId="urn:example:root" Version="1.0" PolicyCombiningAlgId="urn:oasis:names:tc:xacml:3.0:policy-combining-algorithm:deny-overrides"><Target/>';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class, 'Role']);
        Config::set('access.xacml.attributes', [
            'urn:example:order:total'       => 'order.cost',
            'urn:example:order:state'       => 'order.status',
            'urn:example:order:client-city' => 'order.client.city',
            'urn:example:subject:limit'     => 'user.approval_limit',
        ]);
        ShopSchema::create();
        ShopSchema::seed();
    }

    public function test_roles_subjects_targets_variables_and_own_attribute_names_convert(): void
    {
        $xml = self::HEAD.'
          <Policy PolicyId="urn:example:managers" Version="1.0" RuleCombiningAlgId="urn:oasis:names:tc:xacml:3.0:rule-combining-algorithm:deny-overrides">
            <Target><AnyOf><AllOf>'.self::match('urn:oasis:names:tc:xacml:1.0:subject-category:access-subject', 'urn:oasis:names:tc:xacml:2.0:subject:role', 'manager').'</AllOf></AnyOf></Target>
            <VariableDefinition VariableId="cheap">'.self::apply('integer-less-than-or-equal', self::one('integer', self::designator('resource', 'urn:example:order:total', 'integer')), self::one('integer', self::designator('subject', 'urn:example:subject:limit', 'integer'))).'</VariableDefinition>
            <Rule RuleId="view-or-print" Effect="Permit">
              <Target><AnyOf>
                <AllOf>'.self::match('urn:oasis:names:tc:xacml:3.0:attribute-category:action', 'urn:oasis:names:tc:xacml:1.0:action:action-id', 'orders.view').'</AllOf>
                <AllOf>'.self::match('urn:oasis:names:tc:xacml:3.0:attribute-category:action', 'urn:oasis:names:tc:xacml:1.0:action:action-id', 'orders.print').'</AllOf>
              </AnyOf></Target>
              <Condition>'.self::apply('and', '<VariableReference VariableId="cheap"/>', self::apply('string-is-in', self::one('string', self::designator('resource', 'urn:example:order:state', 'string')), self::apply('string-bag', self::value('string', 'draft'), self::value('string', 'review')))).'</Condition>
            </Rule>
            <Rule RuleId="not-in-y" Effect="Deny">
              <Target>
                <AnyOf><AllOf>'.self::match('urn:oasis:names:tc:xacml:3.0:attribute-category:action', 'urn:oasis:names:tc:xacml:1.0:action:action-id', 'orders.view').'</AllOf></AnyOf>
                <AnyOf><AllOf>'.self::match('urn:oasis:names:tc:xacml:3.0:attribute-category:resource', 'urn:example:order:client-city', 'Y').'</AllOf></AnyOf>
              </Target>
            </Rule>
          </Policy>
          <Policy PolicyId="urn:example:ann" Version="1.0" RuleCombiningAlgId="urn:oasis:names:tc:xacml:1.0:rule-combining-algorithm:first-applicable">
            <Target><AnyOf><AllOf>'.self::match('urn:oasis:names:tc:xacml:1.0:subject-category:access-subject', 'urn:oasis:names:tc:xacml:1.0:subject:subject-id', '7').'</AllOf></AnyOf></Target>
            <Rule RuleId="big" Effect="Permit">
              <Target><AnyOf><AllOf>'.self::match('urn:oasis:names:tc:xacml:3.0:attribute-category:action', 'urn:oasis:names:tc:xacml:1.0:action:action-id', 'orders.view').'</AllOf></AnyOf></Target>
              <Condition>'.self::apply('integer-greater-than', self::one('integer', self::designator('resource', 'urn:example:order:total', 'integer')), self::value('integer', '800')).'</Condition>
            </Rule>
            <Rule RuleId="small" Effect="Permit">
              <Target><AnyOf><AllOf>'.self::match('urn:oasis:names:tc:xacml:3.0:attribute-category:action', 'urn:oasis:names:tc:xacml:1.0:action:action-id', 'orders.view').'</AllOf></AnyOf></Target>
              <Condition>'.self::apply('integer-less-than', self::one('integer', self::designator('resource', 'urn:example:order:total', 'integer')), self::value('integer', '100')).'</Condition>
            </Rule>
          </Policy>
        </PolicySet>';

        $report = app(Xacml::class)->import($xml, null, ['subject_type' => TestUser::class]);

        $this->assertSame([], $report['errors']);
        $this->assertTrue($report['written']);
        $this->assertSame(['rules' => 2, 'owners' => 2, 'permissions' => 4, 'inheritance' => 0, 'replaced' => 0], $report['applied']);
        $this->assertFalse($report['own']);

        $this->assertSame([
            'Role manager orders.print permit: order.cost <= user.approval_limit && order.status in [\'draft\', \'review\']',
            'Role manager orders.view permit: order.cost <= user.approval_limit && order.status in [\'draft\', \'review\']',
            "Role manager orders.view prohibit: 'Y' == order.client.city",
            // Two Permit rules of one subject for one action are one permission of the package.
            'TestUser 7 orders.view permit: order.cost > 800 || order.cost < 100',
        ], $this->stored());

        $this->assertSame('order', DB::table(config('access.table_names.rule'))->where('guard_name', 'orders.view')->value('resource'), 'a created rule names its model, or lists could not be filtered');

        // The converted rows decide. The document never said that Ann is a manager, so that stays with the application.
        $ann = TestUser::factory()->make()->forceFill(['id' => 7, 'approval_limit' => 140]);
        $ann->inheritPermissionFrom('Role', 'manager');

        // 5 comes from the role: a draft within the limit. 3 and 6 are her own: cheaper than 100, dearer than 800.
        // Order 3 belongs to a client from city Y, which the role prohibits, and her own permission is stronger:
        // the one place where the package and XACML would part, and the report warns about it.
        $this->assertSame([3, 5, 6], Order::allowedTo('orders.view', $ann)->orderBy('id')->pluck('id')->all());
        $this->assertStringContainsString('an own permission is stronger than an inherited prohibition', implode(' ', array_column($report['warnings'], 1)));
    }

    public function test_what_has_no_counterpart_is_reported_with_its_address_and_nothing_is_written(): void
    {
        $action = '<Target><AnyOf><AllOf>'.self::match('urn:oasis:names:tc:xacml:3.0:attribute-category:action', 'urn:oasis:names:tc:xacml:1.0:action:action-id', 'orders.view').'</AllOf></AnyOf></Target>';
        $role   = '<Target><AnyOf><AllOf>'.self::match('urn:oasis:names:tc:xacml:1.0:subject-category:access-subject', 'urn:oasis:names:tc:xacml:2.0:subject:role', 'manager').'</AllOf></AnyOf></Target>';

        $xml = self::HEAD.'
          <Policy PolicyId="p1" Version="1.0" RuleCombiningAlgId="urn:oasis:names:tc:xacml:3.0:rule-combining-algorithm:permit-unless-deny">'.$role.'
            <Rule RuleId="fine" Effect="Permit">'.$action.'</Rule>
          </Policy>
          <Policy PolicyId="p2" Version="1.0" RuleCombiningAlgId="urn:oasis:names:tc:xacml:3.0:rule-combining-algorithm:permit-overrides">'.$role.'
            <Rule RuleId="yes" Effect="Permit">'.$action.'</Rule>
            <Rule RuleId="no" Effect="Deny">'.$action.'</Rule>
          </Policy>
          <Policy PolicyId="p3" Version="1.0" RuleCombiningAlgId="urn:oasis:names:tc:xacml:3.0:rule-combining-algorithm:deny-overrides">'.$role.'
            <Rule RuleId="regexp" Effect="Deny">'.$action.'<Condition>'.self::apply('string-regexp-match', self::value('string', '^dr'), self::one('string', self::designator('resource', 'urn:example:order:state', 'string'))).'</Condition></Rule>
            <Rule RuleId="unknown-attribute" Effect="Permit">'.$action.'<Condition>'.self::apply('string-equal', self::one('string', self::designator('resource', 'urn:example:order:colour', 'string')), self::value('string', 'red')).'</Condition></Rule>
            <Rule RuleId="no-action" Effect="Permit"/>
            <Rule RuleId="obliged" Effect="Permit">'.$action.'<ObligationExpressions><ObligationExpression ObligationId="log" FulfillOn="Permit"/></ObligationExpressions></Rule>
            <Rule RuleId="wrong-column" Effect="Permit">'.$action.'<Condition>'.self::apply('string-equal', self::one('string', self::designator('resource', 'urn:wnikk:access:resource:order:cost', 'string')), self::value('string', 'many')).'</Condition></Rule>
          </Policy>
          <Policy PolicyId="p4" Version="1.0" RuleCombiningAlgId="urn:oasis:names:tc:xacml:3.0:rule-combining-algorithm:deny-overrides">
            <Target/>
            <Rule RuleId="nobody" Effect="Permit">'.$action.'</Rule>
          </Policy>
          <PolicyIdReference>urn:example:elsewhere</PolicyIdReference>
        </PolicySet>';

        $report = app(Xacml::class)->import($xml);

        $this->assertFalse($report['written']);
        $this->assertSame(0, DB::table(config('access.table_names.permission'))->count());

        $errors = collect($report['errors'])->mapWithKeys(fn ($e) => [preg_replace('/^.*\[([^\]]*)\]$/', '$1', $e[0]) => $e[1]]);

        $this->assertStringContainsString('never permits by default', $errors['p1']);
        $this->assertStringContainsString('lets a permission beat a prohibition', $errors['p2']);
        $this->assertStringContainsString('string-regexp-match', $errors['regexp']);
        $this->assertStringContainsString('"urn:example:order:colour" is unknown', $errors['unknown-attribute']);
        $this->assertStringContainsString('names no action-id', $errors['no-action']);
        $this->assertStringContainsString('no obligations', $errors['obliged']);
        $this->assertStringContainsString('is not a number', $errors['wrong-column'], 'a converted condition passes the checks of the package');
        $this->assertStringContainsString('--everyone', $errors['nobody']);
        $this->assertStringContainsString('urn:example:elsewhere', $errors['urn:example:root']);

        // The same document with --partial and an owner for "everybody": what converts is written.
        Access::for('Role', 'everyone')->create('Everyone');
        $partial = app(Xacml::class)->import($xml, null, ['partial' => true, 'everyone' => 'Role:everyone']);

        $this->assertTrue($partial['written']);
        $this->assertSame(['Role everyone orders.view permit: -'], $this->stored(), 'rules of containers that do not convert stay out as well');
    }

    public function test_documents_that_attack_the_parser_are_refused(): void
    {
        $bomb = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;">]><PolicySet xmlns="urn:oasis:names:tc:xacml:3.0:core:schema:wd-17"><Description>&lol2;</Description></PolicySet>';
        $file = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><PolicySet xmlns="urn:oasis:names:tc:xacml:3.0:core:schema:wd-17"><Description>&secret;</Description></PolicySet>';

        foreach ([$bomb, $file] as $xml) {
            $report = app(Xacml::class)->import($xml);
            $this->assertStringContainsString('DOCTYPE', $report['errors'][0][1]);
        }

        $this->assertStringContainsString('not well-formed', app(Xacml::class)->import('<PolicySet')['errors'][0][1]);
        $this->assertStringContainsString('root has to be', app(Xacml::class)->import('<Policy xmlns="urn:oasis:names:tc:xacml:2.0:policy:schema:os"/>')['errors'][0][1]);
    }

    /**
     * @return list<string> "Role manager orders.view permit: condition"
     */
    private function stored(): array
    {

        return DB::table(config('access.table_names.permission').' as p')
            ->join(config('access.table_names.rule').' as r', 'r.id', '=', 'p.rule_id')
            ->join(config('access.table_names.owner').' as o', 'o.id', '=', 'p.owner_id')
            ->get(['o.type', 'o.original_id', 'r.guard_name', 'r.resource', 'p.permission', 'p.condition'])
            ->map(fn ($row) => class_basename(AccessRules::getListTypes()[$row->type] ?? '?').' '.$row->original_id.' '.$row->guard_name.' '
                .($row->permission ? 'permit' : 'prohibit').': '.($row->condition === null ? '-' : $this->conditionText(json_decode($row->condition, true), $row->resource)))
            ->sort()->values()->all();
    }

    private static function match(string $category, string $id, string $value): string
    {
        return '<Match MatchId="urn:oasis:names:tc:xacml:1.0:function:string-equal">'.self::value('string', $value)
            .'<AttributeDesignator Category="'.$category.'" AttributeId="'.$id.'" DataType="http://www.w3.org/2001/XMLSchema#string" MustBePresent="false"/></Match>';
    }

    private static function apply(string $function, string ...$arguments): string
    {
        $version = str_starts_with($function, 'string-regexp') || in_array($function, ['string-starts-with'], true) ? '3.0' : '1.0';

        return '<Apply FunctionId="urn:oasis:names:tc:xacml:'.$version.':function:'.$function.'">'.implode('', $arguments).'</Apply>';
    }

    private static function one(string $type, string $bag): string
    {
        return self::apply($type.'-one-and-only', $bag);
    }

    private static function value(string $type, string $value): string
    {
        return '<AttributeValue DataType="http://www.w3.org/2001/XMLSchema#'.$type.'">'.$value.'</AttributeValue>';
    }

    private static function designator(string $category, string $id, string $type): string
    {
        $category = $category === 'subject' ? 'urn:oasis:names:tc:xacml:1.0:subject-category:access-subject' : 'urn:oasis:names:tc:xacml:3.0:attribute-category:'.$category;

        return '<AttributeDesignator Category="'.$category.'" AttributeId="'.$id.'" DataType="http://www.w3.org/2001/XMLSchema#'.$type.'" MustBePresent="false"/>';
    }
}
