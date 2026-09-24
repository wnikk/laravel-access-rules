<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
 * streams, paths, an uploaded file, the text itself, and hold the promise of check(): it names
 * every difference between the document and the database, and it writes nothing. The export is
 * one file, and it is valid by the schema of OASIS, manifest set included.
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
        Carbon::setTestNow();
        array_map('unlink', glob($this->work.'/*') ?: []);
        rmdir($this->work);

        parent::tearDown();
    }

    public function test_a_download_is_written_into_whatever_stream_the_caller_holds(): void
    {
        $xacml = app(Xacml::class);

        // What response()->streamDownload() does: the callback writes to php://output.
        ob_start();
        $warnings = $xacml->export('php://output');
        $sent     = (string) ob_get_clean();

        $this->assertSame([], $warnings);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $sent);
        $this->assertStringContainsString('urn:wnikk:access:own:permissions:Role%3Amanager', $sent);

        // One file: the manifest set travels inside the document.
        $manifest = $this->manifestOf($sent);
        $this->assertSame([[TestUser::class.':7', 'Role:manager']], $manifest['inheritance']);
        $this->assertSame(['Role:manager'], $manifest['roles'][TestUser::class.':7']);
        $this->assertSame(['orders', 'orders.view', 'orders.update'], array_column($manifest['rules'], 'guard_name'));
        $this->assertSame('orders', $manifest['rules'][1]['parent']);

        // A stream of the caller stays open: it may belong to a response that has more to send.
        $stream = fopen('php://memory', 'w+');
        $xacml->export($stream);
        $this->assertIsResource($stream);
        rewind($stream);
        $this->assertSame($sent, stream_get_contents($stream));
    }

    /**
     * The manifest set is what makes the export one file, and it is the one part XACML did not
     * ask for. The schema of OASIS decides whether an engine may refuse the document.
     */
    public function test_the_document_is_valid_by_the_schema_of_oasis(): void
    {
        // Something of every kind: a rule with a condition of its own, an option, a warning of the export.
        AccessRules::newRule('orders.export', 'Export', null, null, 'required|in:csv,pdf', 'order', 'not order.locked');
        Access::for('Role', 'manager')->allow('orders.export', 'csv', 'sum(order.items.price) > 10');

        $export = $this->exportXacml();
        $this->assertNotSame([], $export['warnings'], 'the aggregate goes out as text and is named');
        $this->assertSame($export['warnings'], $export['manifest']['warnings']);

        $document = new \DOMDocument;
        $document->loadXML($export['policy']);

        $previous = libxml_use_internal_errors(true);
        $valid    = $document->schemaValidate(__DIR__.'/../Fixtures/Xacml/xacml-core-v3-schema-wd-17.xsd');
        $problems = array_map(static fn ($e) => trim($e->message).' at line '.$e->line, libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($valid, implode("\n", $problems));
    }

    public function test_every_kind_of_source_leads_to_the_same_plan(): void
    {
        $xacml = app(Xacml::class);
        $xacml->export($this->work.'/access.xml');

        $sources = [
            'a path'           => $this->work.'/access.xml',
            'an uploaded file' => new UploadedFile($this->work.'/access.xml', 'access.xml', 'application/xml', null, true),
            'an open stream'   => fopen($this->work.'/access.xml', 'r'),
            'the XML itself'   => file_get_contents($this->work.'/access.xml'),
        ];

        $expected = ['rule' => ['same' => 3], 'owner' => ['same' => 2], 'permission' => ['same' => 2], 'inheritance' => ['same' => 1]];

        foreach ($sources as $name => $source) {
            $this->assertSame($expected, $xacml->check($source)['summary'], $name);
        }

        // An export whose manifest set was cut out knows the permissions only, and says so.
        $document = new \DOMDocument;
        $document->loadXML(file_get_contents($this->work.'/access.xml'));
        foreach ($document->getElementsByTagName('PolicySet') as $set) {
            if ($set->getAttribute('PolicySetId') === 'urn:wnikk:access:manifest') {
                $set->parentNode->removeChild($set);
                break;
            }
        }
        $report = $xacml->check($document->saveXML());

        $this->assertSame(['permission' => ['same' => 2]], $report['summary']);
        $this->assertStringContainsString('no manifest set', $report['warnings'][0][1]);
    }

    public function test_a_check_names_every_difference_and_writes_nothing(): void
    {
        $xacml = app(Xacml::class);
        $xacml->export($this->work.'/access.xml');

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
        $report = $xacml->check($this->work.'/access.xml');

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
        $xacml->export($this->work.'/access.xml');

        Access::for('Role', 'manager')->removeAllow('orders.view');
        Access::for('Role', 'manager')->allow('orders.view', when: 'order.cost > 500');
        Access::for('Role', 'manager')->removeDeny('orders.update');
        DB::table(config('access.table_names.rule'))->where('guard_name', 'orders.view')->update(['title' => 'See orders']);

        $careful = $xacml->import($this->work.'/access.xml');

        $this->assertSame(['rules' => 0, 'owners' => 0, 'permissions' => 1, 'inheritance' => 0, 'replaced' => 0], $careful['applied']);
        $this->assertContains('Role:manager orders.view permit order.cost > 500', $this->rows(), 'the condition of the database stays');
        $this->assertContains('Role:manager orders.update prohibit order.locked == true', $this->rows(), 'what was missing is created');

        $replaced = $xacml->import($this->work.'/access.xml', ['replace' => true]);

        $this->assertSame(['rules' => 0, 'owners' => 0, 'permissions' => 0, 'inheritance' => 0, 'replaced' => 2], $replaced['applied']);
        $this->assertContains('Role:manager orders.view permit order.cost > 100', $this->rows());
        $this->assertSame('View orders', DB::table(config('access.table_names.rule'))->where('guard_name', 'orders.view')->value('title'));

        $this->assertSame(['same'], array_values(array_unique(array_column($xacml->check($this->work.'/access.xml')['changes'], 'action'))));
    }

    /**
     * The usual cycle is export, edit a few rows, import. A plan cannot tell "not created yet"
     * from "removed since the export", so the date of the export has to say when the database
     * moved on after the document.
     */
    public function test_the_plan_says_when_the_database_moved_on_after_the_export(): void
    {
        $xacml = app(Xacml::class);

        Carbon::setTestNow('2030-01-01 10:00:00');
        $xacml->export($this->work.'/access.xml');

        $this->assertSame('2030-01-01T10:00:00+00:00', $this->manifestOf(file_get_contents($this->work.'/access.xml'))['config']['exported_at']);

        $report = $xacml->check($this->work.'/access.xml');
        $this->assertSame('2030-01-01T10:00:00+00:00', $report['exported_at']);
        $this->assertSame([], $report['warnings'], 'nothing moved: nothing to say');

        Carbon::setTestNow('2030-01-01 11:00:00');
        Access::for('Role', 'manager')->removeAllow('orders.view');
        Access::for('Role', 'manager')->allow('orders.view', when: 'order.cost > 500');   // rewritten after the export
        Access::for('Role', 'manager')->removeDeny('orders.update');                        // removed after the export; the document still has it

        $report   = $xacml->check($this->work.'/access.xml');
        $warnings = array_map(static fn (array $w) => $w[0].': '.$w[1], $report['warnings']);

        $this->assertCount(2, $warnings, implode("\n", $warnings));
        $this->assertStringContainsString('Role:manager may orders.view: written in the database on 2030-01-01 11:00, after the export of 2030-01-01 10:00', $warnings[0]);
        $this->assertStringContainsString('/: the database changed on 2030-01-01 11:00, after the export of 2030-01-01 10:00: a row marked "create" may be one that was removed since', $warnings[1]);
        $this->assertSame([], $report['errors'], 'a warning is not an error: the import stays possible');
    }

    public function test_the_console_commands_are_the_same_calls(): void
    {
        $this->artisan('acr:xacml:export', ['target' => $this->work.'/access.xml'])->expectsOutputToContain('0 warning(s)')->assertExitCode(0);
        $this->assertFileExists($this->work.'/access.xml');

        Access::for('Role', 'manager')->removeAllow('orders.view');
        $before = $this->rows();

        $this->artisan('acr:xacml:import', ['source' => $this->work.'/access.xml', '--check' => true])
            ->expectsOutputToContain('Role:manager may orders.view')
            ->expectsOutputToContain('permission: 1 same, 1 create')
            ->assertExitCode(0);
        $this->assertSame($before, $this->rows());

        $this->artisan('acr:xacml:import', ['source' => $this->work.'/access.xml'])->expectsOutputToContain('1 permissions')->assertExitCode(0);
        $this->assertContains('Role:manager orders.view permit order.cost > 100', $this->rows());

        $this->artisan('acr:xacml:import', ['source' => $this->work.'/nothing.xml'])->expectsOutputToContain('Cannot read')->assertExitCode(1);
        $this->artisan('acr:xacml:export', ['target' => $this->work.'/no/such/dir/access.xml'])->expectsOutputToContain('Cannot write')->assertExitCode(1);
    }

    public function test_sources_that_cannot_be_read_say_so(): void
    {
        foreach ([[$this->work.'/missing.xml', 'Cannot read'], [null, 'An XACML source is'], ['', 'An XACML source is']] as [$source, $message]) {
            try {
                app(Xacml::class)->check($source);
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
