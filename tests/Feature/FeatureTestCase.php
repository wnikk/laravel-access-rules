<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;
use Wnikk\LaravelAccessRules\Conditions\Cond;
use Wnikk\LaravelAccessRules\Xacml\Xacml;

/**
 * Base of the tests that hold the package to what its documentation promises.
 *
 * Tests in this directory treat src/ as a black box. They use what an application uses: the
 * traits, the Access facade, Laravel Gate, artisan commands, config, events, the Xacml entry
 * class, and they assert what an application can observe: a decision, a list, a query count,
 * a row in a documented table, the output of a command. A test written against a class of
 * src/ proves that the class does what it does; a test written against a promise fails when
 * the promise is broken, whichever class broke it, and survives every refactoring that keeps it.
 *
 * tests/Unit holds the suite inherited from version 2, kept as the proof that its API still works.
 */
abstract class FeatureTestCase extends TestCase
{
    /**
     * What a list shows and what record-by-record checks open, which the package promises to be the same.
     *
     * @param  class-string<Model>             $model
     * @return array{0:list<int>, 1:list<int>} Ids by allowedTo(), ids by can().
     */
    protected function listedAndOpened(string $model, string $ability, Model $user): array
    {
        return [
            $model::query()->allowedTo($ability, $user)->orderBy('id')->pluck('id')->all(),
            $model::query()->orderBy('id')->get()->filter(fn (Model $record) => $user->can($ability, $record))->pluck('id')->values()->all(),
        ];
    }

    /**
     * The text of a stored condition, the way docs/conditions.md tells an admin panel to get it.
     */
    protected function conditionText(?array $stored, ?string $resource = null): ?string
    {
        return Cond::describe($stored, $resource);
    }

    /**
     * An export the way a controller makes it, through the entry class and into streams.
     *
     * @return array{policy:string, manifest:array, warnings:list<string>}
     */
    protected function exportXacml(): array
    {
        $policy   = fopen('php://memory', 'w+');
        $manifest = fopen('php://memory', 'w+');

        $warnings = app(Xacml::class)->exportPolicy($policy);
        app(Xacml::class)->exportManifest($manifest, $warnings);

        rewind($policy);
        rewind($manifest);

        return ['policy' => (string) stream_get_contents($policy), 'manifest' => json_decode((string) stream_get_contents($manifest), true), 'warnings' => $warnings];
    }
}
