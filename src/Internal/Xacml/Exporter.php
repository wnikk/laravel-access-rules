<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Internal\Xacml;

use DOMDocument;
use DOMElement;
use Wnikk\LaravelAccessRules\Contracts\Inheritance as InheritanceContract;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Contracts\Permission as PermissionContract;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Internal\Administration\TypeRegistry;
use Wnikk\LaravelAccessRules\Internal\Conditions\ConditionCompiler;

/**
 * Writes owners, rules and permissions of the package as one XACML 3.0 policy document.
 *
 * The export exists for the systems around the application: an audit that wants policies in
 * a standard form, another service that asks an XACML engine, a backup that does not depend
 * on table layouts. It is a module on the side. The core never loads it, and a project that
 * does not export pays nothing.
 *
 * The document is flat: a root with "first-applicable" over four policy sets, own prohibitions,
 * own permissions, inherited prohibitions, inherited permissions. That order is the five-step
 * priority of the package, so the root needs no other logic. A policy of the first two sets is
 * addressed to a subject by type and id. A policy of the last two is addressed to everybody
 * whose role attribute holds the owner, and the side that supplies attributes fills that
 * attribute with the whole chain of inheritance; the manifest set lists the chains.
 *
 * The earlier design gave every owner a policy set that referred to the sets of its parents.
 * It needed five sets per owner, a file per set, because engines resolve references between
 * documents and not inside one, and a hundred thousand users became half a million files.
 * It also could not say "own permission beats inherited prohibition" without repeating every
 * ancestor inside every heir.
 *
 * XACML has no place for what a rule is called, how rules are grouped or who inherits from
 * whom. A fifth policy set at the end of the document carries that, addressed to an action no
 * request names, so the export is one file and an engine never evaluates the set. The earlier
 * design kept it in a JSON file next to the policy, and a download became two files or an
 * archive that needed the zip extension.
 *
 * @internal Not part of the public API, it may change in any release. AGENTS.md lists what an application may rely on.
 */
final class Exporter
{
    /** Permissions are read in portions of this many rows, so memory follows one owner and not the table. */
    private const PORTION = 1000;

    public function __construct(
        private Expressions $expressions,
        private TypeRegistry $types,
    ) {}

    /**
     * Writes the document into an open stream, one owner at a time.
     *
     * A stream and not a string, because the callers differ only in where the bytes go: a file
     * for the console, "php://output" for a download, memory for a test. A hundred thousand
     * owners make a document of hundreds of megabytes, and building it as one DOM would need
     * ten times that in memory. Only the policy of one owner is ever a DOM here.
     *
     * The four tiers need four passes over permissions. The alternative is to keep the policies
     * of three tiers in memory while the first is written, which the stream exists to avoid.
     *
     * @param  resource     $stream
     * @return list<string> Warnings: parts of conditions that went out as text of the package, owners of unknown types. They are written into the manifest set too.
     */
    public function document($stream): array
    {
        $warnings = [];
        $rules    = app(RuleContract::class)->newQuery()->get()->keyBy('id');
        $parents  = app(InheritanceContract::class)->newQuery()->select('owner_parent_id');

        fwrite($stream, '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<PolicySet xmlns="'.Vocabulary::NS.'" PolicySetId="'.Vocabulary::ROOT.'" Version="1.0" PolicyCombiningAlgId="'.Vocabulary::ALG_FIRST_APPLICABLE.'">'."\n"
            .'  <Description>Exported by wnikk/laravel-access-rules. The four policy sets are the priority of the package, strongest first.</Description>'."\n"
            ."  <Target/>\n");

        foreach (Vocabulary::TIERS as $name => $tier) {
            fwrite($stream, '  <PolicySet PolicySetId="'.Vocabulary::OWN.$name.'" Version="1.0" PolicyCombiningAlgId="'
                .sprintf($tier['permit'] ? Vocabulary::ALG_PERMIT_OVERRIDES : Vocabulary::ALG_DENY_OVERRIDES, 'policy').'">'."\n    <Target/>\n");

            $held = app(PermissionContract::class)->newQuery()->with('owner')
                ->where('permission', $tier['permit'])
                // Only owners that somebody inherits from can be met as a role.
                ->when(! $tier['own'], fn ($query) => $query->whereIn('owner_id', $parents))
                ->orderBy('owner_id')->orderBy('id')
                ->lazy(self::PORTION);

            $doc = $policy = $ownerId = null;

            foreach ($held as $permission) {
                if ($permission->owner_id !== $ownerId) {
                    $this->flush($stream, $doc, $policy);
                    $ownerId = $permission->owner_id;
                    $type    = $permission->owner === null ? null : $this->types->name((int) $permission->owner->type);

                    if ($type === null) {
                        $warnings[] = 'permissions of owner record #'.$ownerId.' are skipped: its type is not in config access.owner_types';
                        $doc        = $policy = null;

                        continue;
                    }

                    $doc    = new DOMDocument('1.0', 'UTF-8');
                    $policy = $doc->appendChild($this->policyOf($doc, $name, $type, $permission->owner, $tier));
                }

                // A row whose rule is gone was left behind by a delete past the package. The package ignores it, so does the export.
                if ($policy !== null && isset($rules[$permission->rule_id])) {
                    $policy->appendChild($this->rule($doc, $permission, $rules[$permission->rule_id], $warnings));
                }
            }

            $this->flush($stream, $doc, $policy);
            fwrite($stream, "  </PolicySet>\n");
        }

        if (config('access.rule_tree_inheritance')) {
            $warnings[] = 'config access.rule_tree_inheritance is on: a permission for "reports" also covers "reports.sales" inside the package, and the export names only "reports". Grant the rules below explicitly before exporting for another engine.';
        }
        $warnings = array_values(array_unique($warnings));

        $this->manifest($stream, $warnings);
        fwrite($stream, "</PolicySet>\n");

        return $warnings;
    }

    /**
     * Everything the policy cannot hold, as the fifth policy set: what a rule is called and how
     * rules are grouped, names of owners, who inherits from whom, the warnings of the export.
     * "roles" is for the side that answers attribute requests of an XACML engine: the value of
     * the role attribute for every owner that inherits.
     *
     * The set is addressed to an action no request names and holds no policy, so an engine never
     * evaluates it and its advice never reaches a response; the schema allows both. One item is
     * one AdviceExpression, its fields are the attribute assignments, a null field is left out.
     *
     * Written by pieces for the same reason the policy is. Links of inheritance are the one thing
     * held in memory at once, two integers per link, because chains cannot be followed otherwise.
     *
     * @param resource     $stream
     * @param list<string> $warnings Warnings of the four tiers, kept with the export so a download carries them too.
     */
    private function manifest($stream, array $warnings): void
    {
        $compiler = app(ConditionCompiler::class);
        $rules    = app(RuleContract::class)->newQuery()->get()->keyBy('id');

        fwrite($stream, '  <PolicySet PolicySetId="'.Vocabulary::MANIFEST.'" Version="1.0" PolicyCombiningAlgId="'.Vocabulary::ALG_FIRST_APPLICABLE.'">'."\n"
            .'    <Description>What the policy cannot hold: titles and tree of rules, names of owners, inheritance. Addressed to an action no request names, so an engine never evaluates it.</Description>'."\n"
            .'    <Target><AnyOf><AllOf><Match MatchId="'.Vocabulary::FN.'string-equal">'
            .'<AttributeValue DataType="'.Vocabulary::XS.'string">'.Vocabulary::MANIFEST.'</AttributeValue>'
            .'<AttributeDesignator Category="'.Vocabulary::ACTION.'" AttributeId="'.Vocabulary::ACTION_ID.'" DataType="'.Vocabulary::XS.'string" MustBePresent="false"/>'
            ."</Match></AllOf></AnyOf></Target>\n"
            ."    <AdviceExpressions>\n");

        $this->advice($stream, 'config', ['rule_tree_inheritance' => config('access.rule_tree_inheritance') ? 'true' : 'false']);

        foreach ($warnings as $warning) {
            $this->advice($stream, 'warning', ['text' => $warning]);
        }

        foreach ($rules as $rule) {
            $this->advice($stream, 'rule', [
                'guard_name'  => $rule->guard_name,
                'title'       => $rule->title,
                'description' => $rule->description,
                'options'     => $rule->options,
                'resource'    => $rule->resource,
                'origin'      => $rule->origin->value,
                'parent'      => empty($rule->parent_id) ? null : ($rules[$rule->parent_id]->guard_name ?? null),
                'condition'   => $rule->condition === null ? null : $compiler->describe($rule->condition, $rule->resource),
            ]);
        }

        $keys = [];
        foreach (app(OwnerContract::class)->newQuery()->orderBy('id')->lazy(self::PORTION) as $owner) {
            $type = $this->types->name((int) $owner->type);
            if ($type !== null) {
                $keys[$owner->getKey()] = Vocabulary::ownerKey($type, $owner->original_id);
                $this->advice($stream, 'owner', ['type' => $type, 'id' => (string) $owner->original_id, 'name' => $owner->name]);
            }
        }

        $parentsOf = [];
        foreach (app(InheritanceContract::class)->newQuery()->orderBy('id')->lazy(self::PORTION) as $link) {
            if (isset($keys[$link->owner_id], $keys[$link->owner_parent_id])) {
                $parentsOf[$link->owner_id][] = $link->owner_parent_id;
            }
        }

        foreach ($parentsOf as $child => $parents) {
            foreach ($parents as $parent) {
                $this->advice($stream, 'inheritance', ['child' => $keys[$child], 'parent' => $keys[$parent]]);
            }
        }

        foreach (array_keys($parentsOf) as $ownerId) {
            $seen  = [];
            $queue = $parentsOf[$ownerId];
            while ($queue !== []) {
                $parent = array_shift($queue);
                if (! isset($seen[$parent])) {
                    $seen[$parent] = true;
                    $queue         = [...$queue, ...($parentsOf[$parent] ?? [])];
                }
            }

            $this->advice($stream, 'roles', ['owner' => $keys[$ownerId], 'role' => array_map(static fn ($id) => $keys[$id], array_keys($seen))]);
        }

        fwrite($stream, "    </AdviceExpressions>\n  </PolicySet>\n");
    }

    /**
     * One item of the manifest set. A list value repeats the assignment, a null is left out.
     *
     * @param resource                                $stream
     * @param array<string, string|list<string>|null> $fields
     */
    private function advice($stream, string $kind, array $fields): void
    {
        $xml = '      <AdviceExpression AdviceId="'.Vocabulary::MANIFEST.':'.$kind.'" AppliesTo="Permit">'."\n";

        foreach ($fields as $name => $value) {
            foreach ((is_array($value) ? $value : [$value]) as $one) {
                if ($one === null) {
                    continue;
                }

                $xml .= '        <AttributeAssignmentExpression AttributeId="'.Vocabulary::MANIFEST.':'.$name.'">'
                    .'<AttributeValue DataType="'.Vocabulary::XS.'string">'.htmlspecialchars((string) $one, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</AttributeValue>'
                    ."</AttributeAssignmentExpression>\n";
            }
        }

        fwrite($stream, $xml."      </AdviceExpression>\n");
    }

    /**
     * The document as text, for tests and for callers that know the export is small.
     *
     * @return array{policy:string, warnings:list<string>}
     */
    public function export(): array
    {
        $stream   = fopen('php://memory', 'w+');
        $warnings = $this->document($stream);
        rewind($stream);

        return ['policy' => (string) stream_get_contents($stream), 'warnings' => $warnings];
    }

    /**
     * A policy is serialized on its own, so it declares the namespace again. The declaration is
     * removed: the root has made it, and a document of a hundred thousand policies should not
     * repeat sixty bytes in each.
     */
    private function flush($stream, ?DOMDocument $doc, ?DOMElement $policy): void
    {
        if ($doc === null || $policy === null || Expressions::child($policy, 'Rule') === null) {
            return;
        }

        $doc->formatOutput = true;
        $xml               = str_replace(' xmlns="'.Vocabulary::NS.'"', '', (string) $doc->saveXML($policy));

        fwrite($stream, '    '.str_replace("\n", "\n    ", $xml)."\n");
    }

    /**
     * @param array{own:bool, permit:bool} $tier
     */
    private function policyOf(DOMDocument $doc, string $tierName, string $type, OwnerContract $owner, array $tier): DOMElement
    {
        $key = Vocabulary::ownerKey($type, $owner->original_id);

        $policy = $doc->createElementNS(Vocabulary::NS, 'Policy');
        $policy->setAttribute('PolicyId', Vocabulary::OWN.$tierName.':'.rawurlencode($key));
        $policy->setAttribute('Version', '1.0');
        $policy->setAttribute('RuleCombiningAlgId', sprintf($tier['permit'] ? Vocabulary::ALG_PERMIT_OVERRIDES : Vocabulary::ALG_DENY_OVERRIDES, 'rule'));

        $policy->appendChild($doc->createElementNS(Vocabulary::NS, 'Description'))
            ->appendChild($doc->createTextNode(trim(class_basename($type).' '.$owner->original_id.' '.($owner->name === null ? '' : '('.$owner->name.')'))));

        $matches = $tier['own']
            ? [[Vocabulary::SUBJECT, Vocabulary::SUBJECT_TYPE, $type], [Vocabulary::SUBJECT, Vocabulary::SUBJECT_ID, (string) $owner->original_id]]
            : [[Vocabulary::SUBJECT, Vocabulary::SUBJECT_ROLE, $key]];

        $policy->appendChild($this->target($doc, $matches));

        return $policy;
    }

    private function rule(DOMDocument $doc, PermissionContract $permission, RuleContract $definition, array &$warnings): DOMElement
    {
        $ability   = $definition->guard_name;
        $condition = $definition->condition;

        // The suffix ".self" is a permission for the main ability with "the user is the author" as its condition.
        if (str_ends_with($ability, '.self')) {
            $ability   = substr($ability, 0, -5);
            $condition = $condition === null ? ['is-author'] : ['and', ['is-author'], $condition];
        }
        if ($permission->condition !== null) {
            $condition = $condition === null ? $permission->condition : ['and', $condition, $permission->condition];
        }
        if ($permission->option !== null) {
            $ability .= '.'.$permission->option;
        }

        $rule = $doc->createElementNS(Vocabulary::NS, 'Rule');
        $rule->setAttribute('RuleId', Vocabulary::OWN.'permission:'.$permission->getKey());
        $rule->setAttribute('Effect', $permission->permission ? 'Permit' : 'Deny');
        $rule->appendChild($this->target($doc, [[Vocabulary::ACTION, Vocabulary::ACTION_ID, $ability]]));

        if ($condition !== null) {
            if ($definition->resource === null && ConditionCompiler::needsRecord($condition)) {
                $warnings[] = 'rule "'.$definition->guard_name.'" has no resource: attributes of its condition are exported as "resource.*" and their types are guessed from literals';
            }

            $rule->appendChild($doc->createElementNS(Vocabulary::NS, 'Condition'))
                ->appendChild($this->expressions->toXml($doc, $condition, $definition->resource, $warnings));
        }

        return $rule;
    }

    /**
     * @param list<array{0:string, 1:string, 2:string}> $matches Category, attribute id, value. All of them have to match.
     */
    private function target(DOMDocument $doc, array $matches): DOMElement
    {
        $target = $doc->createElementNS(Vocabulary::NS, 'Target');
        $all    = $target->appendChild($doc->createElementNS(Vocabulary::NS, 'AnyOf'))->appendChild($doc->createElementNS(Vocabulary::NS, 'AllOf'));

        foreach ($matches as [$category, $id, $value]) {
            $match = $all->appendChild($doc->createElementNS(Vocabulary::NS, 'Match'));
            $match->setAttribute('MatchId', Vocabulary::FN.'string-equal');
            $match->appendChild($this->expressions->value($doc, 'string', $value));

            $designator = $match->appendChild($doc->createElementNS(Vocabulary::NS, 'AttributeDesignator'));
            $designator->setAttribute('Category', $category);
            $designator->setAttribute('AttributeId', $id);
            $designator->setAttribute('DataType', Vocabulary::XS.'string');
            $designator->setAttribute('MustBePresent', 'false');
        }

        return $target;
    }
}
