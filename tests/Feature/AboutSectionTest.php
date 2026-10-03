<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\Facades\Access;

/**
 * The section of "php artisan about": configuration at a glance and two counts.
 */
class AboutSectionTest extends FeatureTestCase
{
    public function test_about_shows_the_configuration_and_the_counts(): void
    {
        Config::set('access.owner_types', [TestUser::class, 'Role']);
        Config::set('access.tenant_types', ['Team']);
        Access::newRule('orders.view', 'View orders');
        Access::for('Role', 'manager')->create('Managers');

        $types = Access::ownerTypes();
        $this->artisan('about', ['--only' => 'access_rules'])
            ->expectsOutputToContain('Access rules')
            ->expectsOutputToContain('TestUser ('.array_search(TestUser::class, $types, true).'), Role (55693)')
            ->expectsOutputToContain('Team')
            ->expectsOutputToContain('on, store default')
            ->assertExitCode(0);

        Artisan::call('about', ['--only' => 'access_rules', '--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $this->assertSame(['rules' => '1', 'owners' => '1'], array_intersect_key($json['access_rules'], ['rules' => 1, 'owners' => 1]));
    }
}
