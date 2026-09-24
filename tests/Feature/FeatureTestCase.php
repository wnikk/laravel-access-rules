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
     * An export the way a controller makes it, through the entry class and into a stream, with
     * the manifest set read back the way docs/xacml.md describes it to whoever connects an engine.
     *
     * @return array{policy:string, manifest:array, warnings:list<string>}
     */
    protected function exportXacml(): array
    {
        $stream   = fopen('php://memory', 'w+');
        $warnings = app(Xacml::class)->export($stream);
        rewind($stream);
        $policy = (string) stream_get_contents($stream);

        return ['policy' => $policy, 'manifest' => $this->manifestOf($policy), 'warnings' => $warnings];
    }

    /**
     * The manifest set of an export as arrays: rules, owners, inheritance, roles, warnings.
     * Identifiers are spelled out, not taken from constants of the package: a renamed one has to fail here.
     */
    protected function manifestOf(string $policy): array
    {
        $document = new \DOMDocument;
        $document->loadXML($policy);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('x', 'urn:oasis:names:tc:xacml:3.0:core:schema:wd-17');

        $manifest = ['rules' => [], 'owners' => [], 'inheritance' => [], 'roles' => [], 'warnings' => []];

        foreach ($xpath->query('/x:PolicySet/x:PolicySet[@PolicySetId="urn:wnikk:access:manifest"]/x:AdviceExpressions/x:AdviceExpression') as $item) {
            $lists = [];
            foreach ($xpath->query('x:AttributeAssignmentExpression', $item) as $assignment) {
                $lists[substr($assignment->getAttribute('AttributeId'), strlen('urn:wnikk:access:manifest:'))][] = $assignment->textContent;
            }
            $fields = array_map(fn (array $values) => $values[0], $lists);

            switch (substr($item->getAttribute('AdviceId'), strlen('urn:wnikk:access:manifest:'))) {
                case 'rule':
                    $manifest['rules'][] = $fields;
                    break;
                case 'owner':
                    $manifest['owners'][] = $fields;
                    break;
                case 'inheritance':
                    $manifest['inheritance'][] = [$fields['child'], $fields['parent']];
                    break;
                case 'roles':
                    $manifest['roles'][$fields['owner']] = $lists['role'] ?? [];
                    break;
                case 'warning':
                    $manifest['warnings'][] = $fields['text'];
                    break;
            }
        }

        return $manifest;
    }
}
