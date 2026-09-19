<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 *     Owners without a model: a group addressed by a text id gets and checks permissions through AccessRules.
 *
 *
 * The file comes from version 2 and its scenarios stay as they were written. It is part of the
 * compatibility contract: when a test here needs a change, docs/upgrade-2-to-3.md needs a line as well.
 */
class checkOutUserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            'Group',
        ]);

        $acr = $this->getAccessRules();
        $acr->newRule(
            'access-for-out-user',
            'Access for Out User',
        );
    }

    public function test_gate_authorize_out_user_allows_access()
    {
        $acr = $this->getAccessRules();
        $acr->newOwner('Group', 'out_user_id_122', 'Out User');
        $acr->addPermission('access-for-out-user');

        $this->assertTrue($acr->can('access-for-out-user'));
    }

    /**
     * Null, not false. "Nothing is known" and "prohibited" are different answers, and Laravel Gate
     * asks policies only after the first one.
     */
    public function test_gate_authorize_out_user_denies_access()
    {
        $acr = $this->getAccessRules();
        $acr->newOwner('Group', 'out_user_id_124', 'Out User');

        $this->assertNull($acr->can('access-for-out-user'));
    }
}
