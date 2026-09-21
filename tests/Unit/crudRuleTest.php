<?php
namespace Tests\Unit;

use Tests\TestCase;

/**
 * Test class for create and delete access rules.
 *
 * This test checks the creation, soft deletion, and force deletion of access rules.
 */
class crudRuleTest extends TestCase
{
    /**
     * Test adding a new rule.
     */
    public function test_create_rule()
    {
        $acr = $this->getAccessRules();
        $acr->newRule(
            'new-rule-test',
            'Rule for Testing',
            'This rule is created for testing purposes.',
            null,
            'nullable|string'
        );

        // Assert the record exists in the database
        $this->assertDatabaseHas(config('access.table_names.rule'), [
            'guard_name' => 'new-rule-test',
            'title' => 'Rule for Testing',
            'description' => 'This rule is created for testing purposes.',
            'parent_id' => 0, // Default parent_id
            'options' => 'nullable|string',
            'created_at' => now()->toDateTimeString(),
        ]);
    }


    /**
     * Test removing a rule with force delete.
     */
    public function test_remove_rule()
    {
        $acr = $this->getAccessRules();
        $acr->newRule(
            'new-rule-test-real-delete',
            'Rule for Testing Soft Delete',
        );
        // Assert the record exists in the database
        $this->assertDatabaseHas(config('access.table_names.rule'), [
            'guard_name' => 'new-rule-test-real-delete',
        ]);

        $acr->delRule('new-rule-test-real-delete', true);

        // Assert the record is "deleted" (not found by default)
        $this->assertDatabaseMissing(config('access.table_names.rule'), [
            'guard_name' => 'new-rule-test-real-delete',
        ]);
    }

}