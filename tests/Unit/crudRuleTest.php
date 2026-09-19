<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 *     Rules can be created, soft deleted and deleted for good through the entry point of version 2.
 *
 *
 * The file comes from version 2 and its scenarios stay as they were written. It is part of the
 * compatibility contract: when a test here needs a change, docs/upgrade-2-to-3.md needs a line as well.
 */
class crudRuleTest extends TestCase
{
    /**
     * parent_id is 0, not NULL, for a rule without a parent. Tables of version 2 define it that way,
     * and the tree walk of rule inheritance starts from 0.
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

        $this->assertDatabaseHas(config('access.table_names.rule'), [
            'guard_name'  => 'new-rule-test',
            'title'       => 'Rule for Testing',
            'description' => 'This rule is created for testing purposes.',
            'parent_id'   => 0, // Default parent_id
            'options'     => 'nullable|string',
            'created_at'  => now()->toDateTimeString(),
        ]);
    }

    /**
     * The row has to stay in the table. A soft deleted rule keeps its permissions, and restoring the
     * rule restores access; a test that only checks "not found" would pass for a hard delete too.
     */
    public function test_soft_delete_rule()
    {
        $acr = $this->getAccessRules();
        $acr->newRule(
            'new-rule-test-soft-delete',
            'Rule for Testing Soft Delete',
        );
        $this->assertDatabaseHas(config('access.table_names.rule'), [
            'guard_name' => 'new-rule-test-soft-delete',
            'deleted_at' => null,
        ]);

        $acr->delRule('new-rule-test-soft-delete');

        $this->assertDatabaseMissing(config('access.table_names.rule'), [
            'guard_name' => 'new-rule-test-soft-delete',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas(config('access.table_names.rule'), [
            'guard_name' => 'new-rule-test-soft-delete',
        ]);
    }

    public function test_remove_rule()
    {
        $acr = $this->getAccessRules();
        $acr->newRule(
            'new-rule-test-real-delete',
            'Rule for Testing Soft Delete',
        );
        $this->assertDatabaseHas(config('access.table_names.rule'), [
            'guard_name' => 'new-rule-test-real-delete',
        ]);

        $acr->delRule('new-rule-test-real-delete', true);

        $this->assertDatabaseMissing(config('access.table_names.rule'), [
            'guard_name' => 'new-rule-test-real-delete',
        ]);
    }
}
