<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Facades\Access;
use Wnikk\LaravelAccessRules\Xacml\Xacml;

/**
 * The module as its callers see it: a console command, a controller that sends a download,
 * a controller that receives an upload and first shows what it would change.
 *
 * Xacml\Xacml is the one door for all of them. The tests go through every way in and out,
 * streams, paths, a directory, an archive, an uploaded file, and hold the promise of check():
 * it names every difference between the document and the database, and it writes nothing.
 */
class XacmlExchangeTest extends FeatureTestCase
{
    private string $work;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class, 'Role']);
        ShopSchema::create();

        $root = AccessRules::newRule('orders', 'Orders');
        AccessRules::newRule('orders.view', 'View orders', null, $root, null, 'order');
        AccessRules::newRule('orders.update', 'Update orders', null, $root, null, 'order');

        Access::for('Role', 'manager')->create('Managers');
        Access::for('Role', 'manager')->allow('orders.view', when: 'order.cost > 100');
        Access::for('Role', 'manager')->deny('orders.update', when: 'order.locked');
        TestUser::factory()->make()->forceFill(['id' => 7, 'name' => 'Ann'])->inheritPermissionFrom('Role', 'manager');

        $this->work = sys_get_temp_dir().'/acr-xacml-test-'.bin2hex(random_bytes(4));
        mkdir($this->work);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->work.'/*') ?: []);
        rmdir($this->work);

        parent::tearDown();
    }

    public function test_a_download_is_written_into_whatever_stream_the_caller_holds(): void
    {
        $xacml = app(Xacml::class);

        // What response()->streamDownload() does: the callback writes to php://output.
        ob_start();
        $warnings = $xacml->exportPolicy('php://output');
        $sent     = (string) ob_get_clean();

        $this->assertSame([], $warnings);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $sent);
        $this->assertStringContainsString('urn:wnikk:access:own:permissions:Role%3Amanager', $sent);

        // A stream of the caller stays open: it may belong to a response that has more to send.
        $stream = fopen('php://memory', 'w+');
        $xacml->exportManifest($stream, ['kept with the export']);
        $this->assertIsResource($stream);
        rewind($stream);
        $manifest = json_decode((string) stream_get_contents($stream), true);

        $this->assertSame(['kept with the export'], $manifest['warnings']);
        $this->assertSame([[TestUser::class.':7', 'Role:manager']], $manifest['inheritance']);

        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($sent), 'the streamed policy is one well-formed document');
    }

    public function test_every_kind_of_source_leads_to_the_same_plan(): void
    {
        $xacml = app(Xacml::class);
        $xacml->exportDirectory($this->work);
        $xacml->exportArchive($this->work.'/export.zip');

        $sources = [
            'a directory'          => $this->work,
            'a path to the policy' => $this->work.'/policy.xml',
            'a path to an archive' => $this->work.'/export.zip',
            'an uploaded archive'  => new UploadedFile($this->work.'/export.zip', 'access-rules.zip', 'application/zip', null, true),
            'an open stream'       => fopen($this->work.'/export.zip', 'r'),
            'bytes of an archive'  => file_get_contents($this->work.'/export.zip'),
        ];

        $expected = ['rule' => ['same' => 3], 'owner' => ['same' => 2], 'permission' => ['same' => 2], 'inheritance' => ['same' => 1]];

        foreach ($sources as $name => $source) {
            $this->assertSame($expected, $xacml->check($source)['summary'], $name);
        }

        // The policy alone, as text, with the manifest handed in separately or not at all.
        $xml = file_get_contents($this->work.'/policy.xml');
        $this->assertSame($expected, $xacml->check($xml, $this->work.'/manifest.json')['summary']);
        $this->assertSame(['permission' => ['same' => 2]], $xacml->check($xml)['summary']);
    }

    public function test_a_check_names_every_difference_and_writes_nothing(): void
    {
        $xacml = app(Xacml::class);
        $xacml->exportArchive($this->work.'/export.zip');

        // The database moves on after the export.
        Access::for('Role', 'manager')->removeAllow('orders.view');
        Access::for('Role', 'manager')->allow('orders.view', when: 'order.cost > 500');
        Access::for('Role', 'manager')->removeDeny('orders.update');
        Access::for('Role', 'manager')->allow('orders.update');
        Access::for('Role', 'manager')->record()->forceFill(['name' => 'Sales managers'])->save();
        DB::table(config('access.table_names.rule'))->where('guard_name', 'orders.view')->update(['title' => 'See orders']);
        AccessRules::newRule('orders.export', 'Export orders');
        TestUser::factory()->make()->forceFill(['id' => 7])->remInheritFrom('Role', 'manager');
        TestUser::factory()->make()->forceFill(['id' => 9, 'name' => 'Bob'])->inheritPermissionFrom('Role', 'manager');

        $before = $this->rows();
        $report = $xacml->check($this->work.'/export.zip');

        $this->assertSame($before, $this->rows(), 'a check writes nothing');
        $this->assertFalse($report['written']);
        $this->assertTrue($report['own']);
        $this->assertSame([], $report['errors']);

        $changes = collect($report['changes'])->reject(fn ($c) => $c['action'] === 'same')
            ->map(fn ($c) => $c['kind'].' '.$c['action'].': '.$c['what'].' | '.($c['document'] ?? '-').' | '.($c['database'] ?? '-'))->sort()->values()->all();

        $this->assertSame([
            'inheritance create: '.TestUser::class.':7 inherits from Role:manager | - | -',
            'inheritance only_in_database: '.TestUser::class.':9 inherits from Role:manager | - | -',
            'owner differs: Role:manager | Managers | Sales managers',
            'permission create: Role:manager may not orders.update | order.locked == true | -',
            'permission differs: Role:manager may orders.view | order.cost > 100 | order.cost > 500',
            'permission only_in_database: Role:manager may orders.update | - | no condition',
            'rule differs: orders.view | title: View orders; resource: order; parent: orders; origin: code | title: See orders; resource: order; parent: orders; origin: code',
            'rule only_in_database: orders.export | - | title: Export orders; origin: code',
        ], $changes);
    }

    public function test_an_import_leaves_what_differs_alone_unless_told_to_replace(): void
    {
        $xacml = app(Xacml::class);
        $xacml->exportArchive($this->work.'/export.zip');

        Access::for('Role', 'manager')->removeAllow('orders.view');
        Access::for('Role', 'manager')->allow('orders.view', when: 'order.cost > 500');
        Access::for('Role', 'manager')->removeDeny('orders.update');
        DB::table(config('access.table_names.rule'))->where('guard_name', 'orders.view')->update(['title' => 'See orders']);

        $careful = $xacml->import($this->work.'/export.zip');

        $this->assertSame(['rules' => 0, 'owners' => 0, 'permissions' => 1, 'inheritance' => 0, 'replaced' => 0], $careful['applied']);
        $this->assertContains('Role:manager orders.view permit order.cost > 500', $this->rows(), 'the condition of the database stays');
        $this->assertContains('Role:manager orders.update prohibit order.locked == true', $this->rows(), 'what was missing is created');

        $replaced = $xacml->import($this->work.'/export.zip', options: ['replace' => true]);

        $this->assertSame(['rules' => 0, 'owners' => 0, 'permissions' => 0, 'inheritance' => 0, 'replaced' => 2], $replaced['applied']);
        $this->assertContains('Role:manager orders.view permit order.cost > 100', $this->rows());
        $this->assertSame('View orders', DB::table(config('access.table_names.rule'))->where('guard_name', 'orders.view')->value('title'));

        $this->assertSame(['same'], array_values(array_unique(array_column($xacml->check($this->work.'/export.zip')['changes'], 'action'))));
    }

    public function test_the_console_commands_are_the_same_calls(): void
    {
        $this->artisan('acr:xacml:export', ['target' => $this->work])->expectsOutputToContain('0 warning(s)')->assertExitCode(0);
        $this->artisan('acr:xacml:export', ['target' => $this->work.'/export.zip'])->assertExitCode(0);
        $this->assertFileExists($this->work.'/policy.xml');
        $this->assertFileExists($this->work.'/manifest.json');

        Access::for('Role', 'manager')->removeAllow('orders.view');
        $before = $this->rows();

        $this->artisan('acr:xacml:import', ['source' => $this->work.'/export.zip', '--check' => true])
            ->expectsOutputToContain('Role:manager may orders.view')
            ->expectsOutputToContain('permission: 1 same, 1 create')
            ->assertExitCode(0);
        $this->assertSame($before, $this->rows());

        $this->artisan('acr:xacml:import', ['source' => $this->work])->expectsOutputToContain('1 permissions')->assertExitCode(0);
        $this->assertContains('Role:manager orders.view permit order.cost > 100', $this->rows());

        $this->artisan('acr:xacml:import', ['source' => $this->work.'/nothing.xml'])->expectsOutputToContain('Cannot read')->assertExitCode(1);
    }

    public function test_sources_that_cannot_be_read_say_so(): void
    {
        file_put_contents($this->work.'/empty.zip', base64_decode('UEsFBgAAAAAAAAAAAAAAAAAAAAAAAA=='));
        file_put_contents($this->work.'/manifest.json', '{broken');
        file_put_contents($this->work.'/policy.xml', '<PolicySet/>');

        foreach ([[$this->work.'/missing.xml', null, 'Cannot read'], [null, null, 'An XACML source is'], ['<PolicySet/>', $this->work.'/manifest.json', 'not valid JSON']] as [$source, $manifest, $message]) {
            try {
                app(Xacml::class)->check($source, $manifest);
                $this->fail($message);
            } catch (AccessRulesException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /**
     * @return list<string>
     */
    private function rows(): array
    {
        $types = AccessRules::getListTypes();

        return [
            ...DB::table(config('access.table_names.permission').' as p')
                ->join(config('access.table_names.rule').' as r', 'r.id', '=', 'p.rule_id')
                ->join(config('access.table_names.owner').' as o', 'o.id', '=', 'p.owner_id')
                ->get(['o.type', 'o.original_id', 'r.guard_name', 'r.resource', 'p.permission', 'p.condition'])
                ->map(fn ($row) => class_basename($types[$row->type]).':'.$row->original_id.' '.$row->guard_name.' '.($row->permission ? 'permit' : 'prohibit').' '
                    .($row->condition === null ? '-' : $this->conditionText(json_decode($row->condition, true), $row->resource)))->sort()->values()->all(),
            ...DB::table(config('access.table_names.rule'))->orderBy('id')->pluck('title')->map(fn ($t) => 'rule '.$t)->all(),
            ...DB::table(config('access.table_names.owner'))->orderBy('id')->pluck('name')->map(fn ($n) => 'owner '.$n)->all(),
            'links '.DB::table(config('access.table_names.inheritance'))->count(),
        ];
    }
}
