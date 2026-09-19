<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use LogicException;
use Tests\Fixtures\TestUser;
use Tests\TestCase;

/**
 *     A rule can take an option, "rule.option", and the rule decides which options are valid.
 *
 *     Options are the dynamic part that version 2 already had: one rule "edit section" and a permission
 *     per section id, without a rule per section.
 *
 *
 * The file comes from version 2 and its scenarios stay as they were written. It is part of the
 * compatibility contract: when a test here needs a change, docs/upgrade-2-to-3.md needs a line as well.
 */
class checkRuleOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            TestUser::class,
        ]);
        $acr = $this->getAccessRules();
        $acr->newRule(
            'access-options-rule',
            'Access with Options Rule',
            'This rule has options for testing',
            null,
            'required|string|in:option1,option2,option3'
        );
    }

    public function test_gate_authorize_user_allows_access()
    {
        $user = TestUser::factory()->make();
        $user->addPermission('access-options-rule', 'option1');

        $this->assertTrue($user->can('access-options-rule.option1'));
    }

    /**
     * Expects LogicException, the class version 2 threw. The package throws AccessRulesException
     * now, which extends it, and this test is what keeps that inheritance in place.
     */
    public function test_wrong_option_rule_access()
    {
        $user = TestUser::factory()->make();

        $this->expectException(LogicException::class);
        $user->addPermission('access-options-rule', 'option4');

        $this->assertFalse(true, 'Expected LogicException was not thrown');

    }

    public function test_authorize_user_denies_no_rule_access()
    {
        $user = TestUser::factory()->make();

        $this->assertFalse($user->can('access-options-rule.option1'));
    }

    /**
     * A permission for one option says nothing about another option of the same rule.
     */
    public function test_authorize_user_denies_other_rule_access()
    {
        $user = TestUser::factory()->make();
        $user->addPermission('access-options-rule', 'option2');

        $this->assertFalse($user->can('access-options-rule.option1'));
    }
}
