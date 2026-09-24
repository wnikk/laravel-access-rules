<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Internal\Xacml;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use Wnikk\LaravelAccessRules\Administration\RuleCatalog;
use Wnikk\LaravelAccessRules\Contracts\AccessManager;
use Wnikk\LaravelAccessRules\Contracts\Inheritance as InheritanceContract;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Contracts\Permission as PermissionContract;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Internal\Administration\TypeRegistry;
use Wnikk\LaravelAccessRules\Internal\Conditions\ConditionCompiler;
use Wnikk\LaravelAccessRules\Internal\Conditions\ResourceRegistry;
use Wnikk\LaravelAccessRules\Models\RuleOrigin;

/**
 * Converts an XACML 3.0 policy document into owners, rules and permissions of the package,
 * and reports what it could not convert.
 *
 * The package does not run XACML. A document is read once and becomes ordinary rows, which
 * the core then checks at its usual speed. An engine would mean twenty combining algorithms,
 * obligations and two hundred functions, all on the path of every check, for documents most
 * projects will never have.
 *
 * So conversion has an edge, and the class is strict about it. XACML can say things the
 * package cannot: "permit unless denied", obligations, references to other documents, regular
 * expressions. A rule that carries one of them is reported with its address in the document
 * and not written. Nothing is written at all while the report holds an error, unless the caller
 * asks for a partial import: a prohibition that failed to convert without notice leaves access wider
 * than its author meant.
 *
 * check() answers "what would this document change" as a plan and writes nothing; import()
 * executes that same plan. A user interface shows the first and offers the second.
 *
 * Documents written by Exporter are recognised by the id of their root and restored exactly,
 * together with the manifest set that carries names of rules and inheritance. Anything else is
 * a foreign document: every Rule becomes a permission or a prohibition of the subject or the
 * role its Targets name.
 *
 * @internal Not part of the public API, it may change in any release. AGENTS.md lists what an application may rely on.
 */
final class Importer
{
    /** @var list<array{0:string, 1:string}> Address in the document and what is wrong. */
    private array $errors = [];

    /** @var list<array{0:string, 1:string}> */
    private array $warnings = [];

    /** @var list<array{owner:array{0:string, 1:string}, name:?string, ability:string, permit:bool, when:?string, at:string}> */
    private array $statements = [];

    /** @var array{subject_type:?string, role_type:string, everyone:?string} */
    private array $options;

    public function __construct(
        private Expressions $expressions,
        private ConditionCompiler $conditions,
        private ResourceRegistry $resources,
        private RuleCatalog $rules,
        private TypeRegistry $types,
        private AccessManager $access,
    ) {}

    /**
     * Says what an import would do, and changes nothing.
     *
     * The answer is a plan: every rule, owner, permission and link of inheritance the document
     * speaks about, each marked "create", "same" or "differs" against the database as it is now,
     * and for an export of this package also "only_in_database" for what the document lacks.
     * import() executes that plan, so what an administrator reads is what happens next.
     * A dry run that only counted would hide
     * permissions that exist already with another condition.
     *
     * @param array{subject_type?:?string, role_type?:string, everyone?:?string, partial?:bool, replace?:bool} $options
     *                                                                                                                  subject_type: owner type of a subject-id that comes without a type, for foreign documents.
     *                                                                                                                  role_type: owner type of a role named without one, "Role" by default.
     *                                                                                                                  everyone: "Type:id" of the owner that receives rules addressed to nobody in particular.
     *                                                                                                                  partial: import() writes what converts even when something does not.
     *                                                                                                                  replace: import() brings what "differs" to the document; without it such rows stay as they are.
     * @return array{
     *     own:bool, errors:list<array{0:string,1:string}>, warnings:list<array{0:string,1:string}>,
     *     changes:list<array{kind:string, action:string, what:string, document:?string, database:?string}>,
     *     summary:array<string, array<string, int>>, written:bool, applied:array<string, int>
     * } "own" tells an export of this package from a foreign document. "summary" counts changes by kind and action.
     */
    public function check(string $xml, array $options = []): array
    {
        return $this->run($xml, $options, false);
    }

    /**
     * Executes the plan of check(). Nothing is written while the plan holds an error, unless
     * "partial" is given. Everything happens in one transaction with one drop of the cache.
     *
     * @return array The report of check() with "written" and "applied" filled in.
     */
    public function import(string $xml, array $options = []): array
    {
        return $this->run($xml, $options, true);
    }

    private function run(string $xml, array $options, bool $apply): array
    {
        $this->errors  = $this->warnings = $this->statements = [];
        $this->options = ['subject_type' => $options['subject_type'] ?? null, 'role_type' => $options['role_type'] ?? 'Role', 'everyone' => $options['everyone'] ?? null];
        $applied       = ['rules' => 0, 'owners' => 0, 'permissions' => 0, 'inheritance' => 0, 'replaced' => 0];

        $own      = false;
        $manifest = null;
        $root     = $this->parse($xml);

        if ($root !== null) {
            $own = $root->getAttribute('PolicySetId') === Vocabulary::ROOT;

            if ($own) {
                // Inherited tiers repeat own tiers under another address, so only own tiers are read.
                foreach (Expressions::children($root, 'PolicySet') as $tier) {
                    if (str_starts_with($tier->getAttribute('PolicySetId'), Vocabulary::OWN.'own:')) {
                        $this->walk($tier, ['subjects' => null, 'actions' => null, 'when' => []], '', false);
                    }
                }

                $manifest = $this->manifest($root);
                if ($manifest === null) {
                    $this->warnings[] = ['/', 'the export has no manifest set: titles of rules, names of owners and inheritance are not restored'];
                }
            } else {
                $this->walk($root, ['subjects' => null, 'actions' => null, 'when' => []], '', true);
                $this->warnAboutOwnAgainstRole();
            }
        }

        $plan       = $root === null ? [] : $this->plan($manifest ?? [], $own && $manifest !== null);
        $exportedAt = $this->warnAboutAge($plan, $manifest);
        $written    = $apply && $root !== null && ($this->errors === [] || ($options['partial'] ?? false));

        if ($written) {
            app(RuleContract::class)->getConnection()->transaction(function () use ($plan, $options, &$applied) {
                $this->access->batch(function () use ($plan, $options, &$applied) {
                    $this->apply($plan, (bool) ($options['replace'] ?? false), $applied);
                });
            });
        }

        $summary = [];
        foreach ($plan as $change) {
            $summary[$change['kind']][$change['action']] = ($summary[$change['kind']][$change['action']] ?? 0) + 1;
        }

        return [
            'own'         => $own, 'errors' => $this->errors, 'warnings' => $this->warnings,
            'exported_at' => $exportedAt?->format(DATE_ATOM),
            'changes'     => array_map(static fn (array $c) => array_intersect_key($c, array_flip(['kind', 'action', 'what', 'document', 'database'])), $plan),
            'summary'     => $summary, 'written' => $written, 'applied' => $applied,
        ];
    }

    /**
     * The usual cycle is export, edit a few rows, import. Between the two the database may have
     * moved on, and the plan alone cannot tell "not created yet" from "removed since the export":
     * a removed row is in the document and not in the database, which reads as "create". The
     * date of the export makes the difference visible. A permission the database rewrote after
     * that date is named row by row, because "replace" would bring back the older version; a
     * row removed since leaves no trace, so when anything in the database is newer than the
     * document and the plan holds a "create", one warning asks to read those rows first.
     *
     * Rules and owners carry no date of their last change, so only their creation counts here.
     *
     * @param  list<array> $plan
     * @return Carbon|null When the document was exported, when it says so.
     */
    private function warnAboutAge(array $plan, ?array $manifest): ?Carbon
    {
        $since = $manifest['config']['exported_at'] ?? null;
        if (! is_string($since) || $since === '') {
            return null;
        }

        try {
            $since = Carbon::parse($since);
        } catch (\Throwable) {
            return null;
        }

        $creates = false;
        foreach ($plan as $change) {
            $creates = $creates || $change['action'] === 'create';

            if ($change['action'] === 'differs' && ! empty($change['written_at']) && Carbon::parse($change['written_at'])->greaterThan($since)) {
                $this->warnings[] = [$change['what'], 'written in the database on '.Carbon::parse($change['written_at'])->format('Y-m-d H:i').', after the export of '.$since->format('Y-m-d H:i').'; "replace" would bring back the older version of the document'];
            }
        }

        $latest = null;
        foreach ([RuleContract::class, OwnerContract::class, PermissionContract::class, InheritanceContract::class] as $contract) {
            $at = app($contract)->newQuery()->max('created_at');
            if ($at !== null && ($latest === null || Carbon::parse($at)->greaterThan($latest))) {
                $latest = Carbon::parse($at);
            }
        }

        if ($creates && $latest !== null && $latest->greaterThan($since)) {
            $this->warnings[] = ['/', 'the database changed on '.$latest->format('Y-m-d H:i').', after the export of '.$since->format('Y-m-d H:i').': a row marked "create" may be one that was removed since; read those rows before importing'];
        }

        return $since;
    }

    /**
     * The manifest set of an export, read back: one item per AdviceExpression, its fields from
     * the attribute assignments, a repeated field as a list. Null when the document has no such
     * set, which a hand-edited export may lack; the plan then knows the permissions only.
     *
     * @return array{config:array<string, mixed>, warnings:list<string>, rules:list<array<string, string>>, owners:list<array<string, string>>, inheritance:list<array{0:string, 1:string}>, roles:array<string, list<string>>}|null
     */
    private function manifest(DOMElement $root): ?array
    {
        $advices = null;
        foreach (Expressions::children($root, 'PolicySet') as $set) {
            if ($set->getAttribute('PolicySetId') === Vocabulary::MANIFEST) {
                $advices = Expressions::child($set, 'AdviceExpressions');
            }
        }
        if ($advices === null) {
            return null;
        }

        $manifest = ['config' => [], 'warnings' => [], 'rules' => [], 'owners' => [], 'inheritance' => [], 'roles' => []];
        $prefix   = Vocabulary::MANIFEST.':';

        foreach (Expressions::children($advices, 'AdviceExpression') as $item) {
            $lists = [];
            foreach (Expressions::children($item, 'AttributeAssignmentExpression') as $assignment) {
                $value = Expressions::child($assignment, 'AttributeValue');
                if ($value !== null) {
                    $lists[substr($assignment->getAttribute('AttributeId'), strlen($prefix))][] = $value->textContent;
                }
            }
            $fields = array_map(static fn (array $values): string => $values[0], $lists);

            switch (substr($item->getAttribute('AdviceId'), strlen($prefix))) {
                case 'config':
                    $manifest['config'] = array_map(static fn (string $v): mixed => $v === 'true' ? true : ($v === 'false' ? false : $v), $fields);
                    break;
                case 'warning':
                    $manifest['warnings'][] = $fields['text'] ?? '';
                    break;
                case 'rule':
                    $manifest['rules'][] = $fields;
                    break;
                case 'owner':
                    $manifest['owners'][] = $fields;
                    break;
                case 'inheritance':
                    $manifest['inheritance'][] = [$fields['child'] ?? '', $fields['parent'] ?? ''];
                    break;
                case 'roles':
                    $manifest['roles'][$fields['owner'] ?? ''] = $lists['role'] ?? [];
                    break;
            }
        }

        return $manifest;
    }

    /**
     * A DOCTYPE is refused before the parser sees it. Entities are how XML reads local files
     * and how a kilobyte of text becomes gigabytes of memory, and a policy has no use for them.
     * LIBXML_NONET keeps the parser off the network; the depth limit of libxml stays on.
     */
    private function parse(string $xml): ?DOMElement
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            $this->errors[] = ['/', 'the document declares a DOCTYPE or entities, which a policy never needs; refused'];

            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc      = new DOMDocument;
        $loaded   = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        $problem  = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $loaded ? $doc->documentElement : null;

        if ($root === null) {
            $this->errors[] = ['/', 'not well-formed XML'.($problem ? ': '.trim($problem->message).' at line '.$problem->line : '')];

            return null;
        }
        if ($root->namespaceURI !== Vocabulary::NS || ! in_array($root->localName, ['PolicySet', 'Policy'], true)) {
            $this->errors[] = ['/', 'the root has to be a PolicySet or a Policy of XACML 3.0 ('.Vocabulary::NS.'), got <'.$root->localName.'> of "'.$root->namespaceURI.'"'];

            return null;
        }

        return $root;
    }

    /**
     * @param  array{subjects:?list<array{0:string,1:string}>, actions:?list<string>, when:list<string>} $context What the Targets above have said so far.
     * @param  bool                                                                                      $check   False for documents of Exporter, whose algorithms are known to fit.
     * @return list<bool>                                                                                Effects of the rules below, in document order, for the check of the algorithm above.
     */
    private function walk(DOMElement $e, array $context, string $at, bool $check): array
    {
        $at .= '/'.$e->localName.'['.($e->getAttribute('PolicySetId') ?: $e->getAttribute('PolicyId') ?: $e->getAttribute('RuleId')).']';

        $target = Expressions::child($e, 'Target');
        if ($target !== null) {
            $context = $this->narrow($context, $target, $at);
            if ($context === null) {
                return [];
            }
        }

        if ($e->localName === 'Rule') {
            return $this->rule($e, $context, $at);
        }

        $variables = [];
        foreach (Expressions::children($e, 'VariableDefinition') as $definition) {
            $variables[$definition->getAttribute('VariableId')] = $definition;
        }

        $effects = [];
        $before  = count($this->statements);

        foreach (Expressions::children($e) as $child) {
            switch ($child->localName) {
                case 'PolicySet':
                case 'Policy':
                    $effects = [...$effects, ...$this->walk($child, $context, $at, $check)];
                    break;
                case 'Rule':
                    $effects = [...$effects, ...$this->walk($child, $context + ['variables' => $variables], $at, $check)];
                    break;
                case 'ObligationExpressions':
                case 'AdviceExpressions':
                    // An obligation is part of the decision: the side that enforces it must fulfil it or deny.
                    // The package has nobody to hand it to, and dropping it would change what the policy means.
                    $this->errors[] = [$at, '<'.$child->localName.'> cannot be converted: the package has no obligations'];
                    break;
                case 'PolicySetIdReference':
                case 'PolicyIdReference':
                    $this->errors[] = [$at, 'reference to "'.trim((string) $child->textContent).'" is not followed: import the referenced document as well'];
                    break;
                case 'PolicyIssuer':
                case 'PolicyDefaults':
                case 'PolicySetDefaults':
                case 'CombinerParameters':
                case 'RuleCombinerParameters':
                case 'PolicyCombinerParameters':
                case 'PolicySetCombinerParameters':
                    $this->warnings[] = [$at, '<'.$child->localName.'> is ignored'];
                    break;
            }
        }

        // Rules of a container whose algorithm does not convert mean something else than they say,
        // so a partial import must not write them either.
        if ($check && ! $this->checkAlgorithm($e, $effects, $at)) {
            array_splice($this->statements, $before);
        }

        return $effects;
    }

    /**
     * @return list<bool>
     */
    private function rule(DOMElement $rule, array $context, string $at): array
    {
        $permit = $rule->getAttribute('Effect') === 'Permit';

        foreach (['ObligationExpressions', 'AdviceExpressions'] as $unsupported) {
            if (Expressions::child($rule, $unsupported) !== null) {
                $this->errors[] = [$at, '<'.$unsupported.'> cannot be converted: the package has no obligations'];

                return [$permit];
            }
        }

        $when      = $context['when'];
        $condition = Expressions::child($rule, 'Condition');
        if ($condition !== null) {
            try {
                $when[] = $this->expressions->toText($condition, $context['variables'] ?? []);
            } catch (\Throwable $e) {
                $this->errors[] = [$at, 'condition cannot be converted: '.$e->getMessage()];

                return [$permit];
            }
        }

        $subjects = $context['subjects'];
        if ($subjects === null) {
            if ($this->options['everyone'] === null) {
                $this->errors[] = [$at, 'the rule names no subject and no role. The package grants to owners: pass --everyone=Type:id to give such rules to one owner that everybody inherits from'];

                return [$permit];
            }
            $subjects = [self::splitKey($this->options['everyone'])];
        }

        if ($context['actions'] === null) {
            $this->errors[] = [$at, 'the rule names no action-id. The package grants abilities by name and has no "every action"'];

            return [$permit];
        }

        foreach ($subjects as $subject) {
            foreach ($context['actions'] as $action) {
                $this->statements[] = [
                    'owner' => $subject, 'name' => null, 'ability' => $action, 'permit' => $permit,
                    'when'  => $when === [] ? null : implode(' && ', $when), 'at' => $at,
                ];
            }
        }

        return [$permit];
    }

    /**
     * A Target is a conjunction of AnyOf, each a disjunction of AllOf, each a conjunction of Match.
     * Matches of a subject and of an action become the owner and the ability; every other Match
     * is a comparison and joins the condition.
     *
     * @return array|null Null when the Target cannot be converted; the error is already recorded.
     */
    private function narrow(array $context, DOMElement $target, string $at): ?array
    {
        foreach (Expressions::children($target, 'AnyOf') as $anyOf) {
            $alternatives = [];

            foreach (Expressions::children($anyOf, 'AllOf') as $allOf) {
                $part = ['type' => null, 'id' => null, 'role' => null, 'action' => null, 'when' => []];

                foreach (Expressions::children($allOf, 'Match') as $match) {
                    $designator = Expressions::child($match, 'AttributeDesignator');
                    $id         = $designator?->getAttribute('AttributeId');
                    $slot       = [Vocabulary::SUBJECT_TYPE => 'type', Vocabulary::SUBJECT_ID => 'id', Vocabulary::SUBJECT_ROLE => 'role', Vocabulary::ACTION_ID => 'action'][$id] ?? null;

                    try {
                        if ($slot === null) {
                            $part['when'][] = $this->expressions->matchToText($match);
                        } elseif (! str_ends_with($match->getAttribute('MatchId'), '-equal')) {
                            throw new \RuntimeException('a subject or an action is matched with '.$match->getAttribute('MatchId').'; the package knows them by exact name');
                        } else {
                            $part[$slot] = trim((string) Expressions::child($match, 'AttributeValue')?->textContent);
                        }
                    } catch (\Throwable $e) {
                        $this->errors[] = [$at, 'Target cannot be converted: '.$e->getMessage()];

                        return null;
                    }
                }

                $alternatives[] = $part;
            }

            $context = $this->merge($context, $alternatives, $at);
            if ($context === null) {
                return null;
            }
        }

        return $context;
    }

    private function merge(array $context, array $alternatives, string $at): ?array
    {
        $subjects = $actions = $conditions = [];

        foreach ($alternatives as $part) {
            $kinds = 0;

            if ($part['id'] !== null || $part['role'] !== null || $part['type'] !== null) {
                $kinds++;
                $subject = $this->subject($part, $at);
                if ($subject === null) {
                    return null;
                }
                $subjects[] = $subject;
            }
            if ($part['action'] !== null) {
                $kinds++;
                $actions[] = $part['action'];
            }
            if ($part['when'] !== []) {
                $kinds++;
                $conditions[] = '('.implode(' && ', $part['when']).')';
            }

            // One AllOf may say everything at once. Several of them are alternatives, and "this
            // subject with that action, or another subject with another action" is not a list of owners.
            if ($kinds > 1 && count($alternatives) > 1) {
                $this->errors[] = [$at, 'Target has alternatives that mix subjects, actions and other attributes; split it into separate rules'];

                return null;
            }
        }

        if ($subjects !== []) {
            $context['subjects'] = $context['subjects'] === null ? $subjects : array_values(array_filter($context['subjects'], static fn ($s) => in_array($s, $subjects, true)));
        }
        if ($actions !== []) {
            $context['actions'] = $context['actions'] === null ? $actions : array_values(array_intersect($context['actions'], $actions));
        }
        if ($conditions !== []) {
            $context['when'][] = count($conditions) === 1 ? $conditions[0] : '('.implode(' || ', $conditions).')';
        }

        return $context;
    }

    /**
     * @return array{0:string, 1:string}|null
     */
    private function subject(array $part, string $at): ?array
    {
        if ($part['role'] !== null) {
            [$type, $id] = self::splitKey($part['role']);

            // "Role:manager" of our own export, or a bare "manager" of a foreign document.
            return $this->knownType($type) && $id !== '' ? [$type, $id] : [$this->options['role_type'], $part['role']];
        }

        $type = $part['type'] ?? $this->options['subject_type'];
        if ($part['id'] === null || $type === null) {
            $this->errors[] = [$at, $part['id'] === null
                ? 'Target names a type of subject without an id'
                : 'subject "'.$part['id'].'" comes without a type; pass --subject-type with one of config access.owner_types'];

            return null;
        }

        return [$type, $part['id']];
    }

    /**
     * The package lets a prohibition beat a permission of the same owner, always. A container
     * converts without a change of meaning only if its algorithm gives the same answer for the
     * rules it holds.
     *
     * @param list<bool> $effects
     */
    private function checkAlgorithm(DOMElement $container, array $effects, string $at): bool
    {
        $urn  = $container->getAttribute($container->localName === 'Policy' ? 'RuleCombiningAlgId' : 'PolicyCombiningAlgId');
        $kind = Vocabulary::ALGORITHMS[Vocabulary::local($urn)] ?? null;

        $denies         = in_array(false, $effects, true);
        $permitThenDeny = $denies && in_array(true, array_slice($effects, 0, (int) array_search(false, array_reverse($effects, true), true)), true);

        $problem = match (true) {
            $kind === null                       => 'combining algorithm "'.$urn.'" is unknown',
            $kind === false                      => 'combining algorithm "'.Vocabulary::local($urn).'" has no counterpart: the package never permits by default and does not pick one policy of many',
            $kind === 'permit' && $denies        => '"'.Vocabulary::local($urn).'" lets a permission beat a prohibition, the package does the opposite; this container holds prohibitions',
            $kind === 'order' && $permitThenDeny => '"first-applicable" with a Permit before a Deny lets the permission win, the package does the opposite; put prohibitions first or use deny-overrides',
            default                              => null,
        };

        if ($problem !== null) {
            $this->errors[] = [$at, $problem];
        }

        return $problem === null;
    }

    /**
     * In XACML a prohibition of a role beats a permission given to one user, under deny-overrides.
     * In the package an own permission is stronger than anything inherited. The document does not
     * say who holds which role, so this can only be pointed at, not decided.
     */
    private function warnAboutOwnAgainstRole(): void
    {
        $roleDenies = [];
        foreach ($this->statements as $s) {
            if (! $s['permit'] && ! class_exists($s['owner'][0])) {
                $roleDenies[$s['ability']][] = Vocabulary::ownerKey(...$s['owner']);
            }
        }

        foreach ($this->statements as $s) {
            if ($s['permit'] && class_exists($s['owner'][0]) && isset($roleDenies[$s['ability']])) {
                $this->warnings[] = [$s['at'], '"'.$s['ability'].'" is permitted to '.Vocabulary::ownerKey(...$s['owner']).' and prohibited to '.implode(', ', array_unique($roleDenies[$s['ability']]))
                    .'. If this subject holds that role, XACML denies and the package permits: an own permission is stronger than an inherited prohibition'];
            }
        }
    }

    /**
     * Compares the document with the database and lists what differs, without a single write.
     *
     * Rules are looked up in a picture of the future: rules of the database plus rules of the
     * manifest that do not exist yet. Without it a check of an empty database could not tell
     * that "orders.update.self" will exist when its permission arrives, and would plan something
     * else than import() then does.
     *
     * The database is read whole, a query per table. An import is rare and runs in a console or
     * a queue; a query per permission of a large document would take minutes.
     *
     * @param  bool        $complete True for an export with its manifest set: only then "the document lacks it" means anything.
     * @return list<array> Changes; each also carries what apply() needs to execute it.
     */
    private function plan(array $manifest, bool $complete): array
    {
        $plan = [];

        // ---- rules
        $rules = [];
        foreach (app(RuleContract::class)->newQuery()->with('parent')->get() as $rule) {
            $rules[$rule->guard_name] = [
                'exists' => true, 'options' => $rule->options, 'resource' => $rule->resource, 'condition' => $rule->condition,
                'text'   => [$rule->title, $rule->description, $rule->options, $rule->resource, empty($rule->parent_id) ? null : $rule->parent?->guard_name, $this->conditions->describe($rule->condition, $rule->resource), $rule->origin->value],
            ];
        }

        foreach ($manifest['rules'] ?? [] as $rule) {
            $name = (string) $rule['guard_name'];

            try {
                $condition = $this->conditions->compile($rule['condition'] ?? null, $rule['resource'] ?? null);
            } catch (\Throwable $e) {
                $this->errors[] = ['manifest: rule '.$name, 'condition "'.$rule['condition'].'" is refused: '.$e->getMessage()];

                continue;
            }

            // A manifest written before origins existed says nothing, and nothing means code.
            $origin = (RuleOrigin::tryFrom((string) ($rule['origin'] ?? '')) ?? RuleOrigin::Code)->value;
            $text   = [$rule['title'] ?? null, $rule['description'] ?? null, $rule['options'] ?? null, $rule['resource'] ?? null, $rule['parent'] ?? null, $this->conditions->describe($condition, $rule['resource'] ?? null), $origin];
            $action = ! isset($rules[$name]) ? 'create' : ($rules[$name]['text'] == $text ? 'same' : 'differs');
            $plan[] = ['kind' => 'rule', 'action' => $action, 'what' => $name, 'document' => self::ruleText($text), 'database' => $action === 'differs' ? self::ruleText($rules[$name]['text']) : null, 'rule' => ['origin' => $origin] + $rule + ['condition_tree' => $condition]];

            if ($action === 'create') {
                $rules[$name] = ['exists' => false, 'options' => $rule['options'] ?? null, 'resource' => $rule['resource'] ?? null, 'condition' => $condition, 'text' => $text];
            }
        }

        // ---- owners
        $owners = [];
        $keyOf  = [];
        foreach (app(OwnerContract::class)->newQuery()->get() as $owner) {
            $type = $this->types->name((int) $owner->type);
            if ($type !== null) {
                $key                     = Vocabulary::ownerKey($type, $owner->original_id);
                $owners[$key]            = $owner->name;
                $keyOf[$owner->getKey()] = $key;
            }
        }

        $planned = [];
        foreach ($manifest['owners'] ?? [] as $owner) {
            $key = Vocabulary::ownerKey((string) $owner['type'], $owner['id']);
            if (! $this->knownType((string) $owner['type'])) {
                $this->errors[] = ['manifest: owner '.$key, 'owner type "'.$owner['type'].'" is not in config access.owner_types'];

                continue;
            }

            $planned[$key] = true;
            $action        = ! array_key_exists($key, $owners) ? 'create' : ($owners[$key] == ($owner['name'] ?? null) ? 'same' : 'differs');
            $plan[]        = ['kind' => 'owner', 'action' => $action, 'what' => $key, 'document' => $owner['name'] ?? null, 'database' => $action === 'differs' ? $owners[$key] : null, 'owner' => [$owner['type'], $owner['id']]];
        }

        // ---- permissions
        $held = [];
        foreach (app(PermissionContract::class)->newQuery()->with('rule')->get() as $permission) {
            if (isset($keyOf[$permission->owner_id]) && $permission->rule !== null) {
                $held[self::permissionKey($keyOf[$permission->owner_id], $permission->rule->guard_name, $permission->option, (bool) $permission->permission)] = [
                    'condition'  => $permission->condition,
                    'created_at' => $permission->created_at,
                    'what'       => $keyOf[$permission->owner_id].' '.($permission->permission ? 'may' : 'may not').' '.$permission->rule->guard_name.($permission->option === null ? '' : '.'.$permission->option),
                    'resource'   => $permission->rule->resource,
                ];
            }
        }

        $seen = [];
        foreach ($this->merged() as $s) {
            $ownerKey = Vocabulary::ownerKey(...$s['owner']);

            if (! $this->knownType($s['owner'][0])) {
                $this->errors[] = [$s['at'], 'owner type "'.$s['owner'][0].'" is not in config access.owner_types'];

                continue;
            }

            [$name, $option] = $this->locate($s['ability'], $rules);
            $resource        = ($name === null ? null : ($rules[$name]['resource'] ?? $rules[$name.'.self']['resource'] ?? null)) ?? $this->aliasIn((string) $s['when']);

            try {
                $tree = $s['when'] === null ? null : $this->conditions->compile($s['when'], $resource);
            } catch (\Throwable $e) {
                $this->errors[] = [$s['at'], 'condition "'.$s['when'].'" is refused: '.$e->getMessage()];

                continue;
            }

            if ($name === null) {
                $name         = $s['ability'];
                $rules[$name] = ['exists' => false, 'options' => null, 'resource' => $resource, 'condition' => null, 'text' => []];
                $plan[]       = [
                    'kind' => 'rule', 'action' => 'create', 'what' => $name, 'document' => 'named by the document only: no title, origin: import'.($resource === null ? '' : ', resource '.$resource), 'database' => null,
                    // Nobody has confirmed that code checks this name, so an administrator may rename or remove it.
                    'rule' => ['guard_name' => $name, 'title' => $name, 'resource' => $resource, 'parent' => null, 'condition_tree' => null, 'origin' => RuleOrigin::Import->value],
                ];
            }

            [$name, $tree] = $this->restore($name, $tree, $rules);

            if ($option !== null) {
                try {
                    $this->rules->checkOption(app(RuleContract::class)->newInstance(['guard_name' => $name, 'options' => $rules[$name]['options']]), $option);
                } catch (AccessRulesException $e) {
                    $this->errors[] = [$s['at'], $e->getMessage()];

                    continue;
                }
            }

            if (! array_key_exists($ownerKey, $owners) && ! isset($planned[$ownerKey])) {
                $planned[$ownerKey] = true;
                $plan[]             = ['kind' => 'owner', 'action' => 'create', 'what' => $ownerKey, 'document' => null, 'database' => null, 'owner' => $s['owner']];
            }

            $key        = self::permissionKey($ownerKey, $name, $option, $s['permit']);
            $seen[$key] = true;
            $text       = $this->conditions->describe($tree, $rules[$name]['resource']) ?? 'no condition';
            $current    = isset($held[$key]) ? ($this->conditions->describe(self::canonical($held[$key]['condition']), $rules[$name]['resource']) ?? 'no condition') : null;

            $plan[] = [
                'kind'     => 'permission', 'action' => $current === null ? 'create' : ($current === $text ? 'same' : 'differs'),
                'what'     => $ownerKey.' '.($s['permit'] ? 'may' : 'may not').' '.$name.($option === null ? '' : '.'.$option),
                'document' => $text, 'database' => $current === $text ? null : $current,
                // When the row of the database was written, for the warning about a document older than it.
                'written_at' => isset($held[$key]) ? $held[$key]['created_at'] : null,
                'grant'      => ['owner' => $s['owner'], 'rule' => $name, 'option' => $option, 'permit' => $s['permit'], 'tree' => $tree],
            ];
        }

        // ---- inheritance
        $links = [];
        foreach (app(InheritanceContract::class)->newQuery()->get() as $link) {
            if (isset($keyOf[$link->owner_id], $keyOf[$link->owner_parent_id])) {
                $links[$keyOf[$link->owner_id].' < '.$keyOf[$link->owner_parent_id]] = true;
            }
        }

        foreach ($manifest['inheritance'] ?? [] as [$child, $parent]) {
            $pair   = $child.' < '.$parent;
            $plan[] = ['kind' => 'inheritance', 'action' => isset($links[$pair]) ? 'same' : 'create', 'what' => $child.' inherits from '.$parent, 'document' => null, 'database' => null, 'link' => [self::splitKey($child), self::splitKey($parent)]];
            unset($links[$pair]);
        }

        // ---- what the database has and a complete document lacks. An import never deletes; the list is for the reader.
        if ($complete) {
            foreach (array_diff_key($held, $seen) as $row) {
                $plan[] = ['kind' => 'permission', 'action' => 'only_in_database', 'what' => $row['what'], 'document' => null, 'database' => $this->conditions->describe($row['condition'], $row['resource']) ?? 'no condition'];
            }
            foreach (array_keys($links) as $pair) {
                $plan[] = ['kind' => 'inheritance', 'action' => 'only_in_database', 'what' => str_replace(' < ', ' inherits from ', $pair), 'document' => null, 'database' => null];
            }
            foreach (array_diff_key($rules, array_flip(array_column($manifest['rules'] ?? [], 'guard_name'))) as $name => $rule) {
                if ($rule['exists']) {
                    $plan[] = ['kind' => 'rule', 'action' => 'only_in_database', 'what' => $name, 'document' => null, 'database' => self::ruleText($rule['text'])];
                }
            }
        }

        return $plan;
    }

    /**
     * Rules that the package keeps as one row are merged: two Permit rules of one owner for one
     * ability are one permission with "or", and a rule without a condition makes the others moot.
     *
     * @return list<array{owner:array{0:string,1:string}, ability:string, permit:bool, when:?string, at:string}>
     */
    private function merged(): array
    {
        $merged = [];
        foreach ($this->statements as $s) {
            $key = Vocabulary::ownerKey(...$s['owner']).'|'.$s['ability'].'|'.(int) $s['permit'];

            if (! isset($merged[$key])) {
                $merged[$key] = $s;
            } elseif ($merged[$key]['when'] !== null) {
                $merged[$key]['when'] = $s['when'] === null ? null : '('.$merged[$key]['when'].') || ('.$s['when'].')';
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, array>        $rules The picture of the future from plan().
     * @return array{0:?string, 1:?string} Name of the rule and the option, as RuleCatalog::resolve() finds them once the import is done.
     */
    private function locate(string $ability, array $rules): array
    {
        if (isset($rules[$ability]) || isset($rules[$ability.'.self'])) {
            return [$ability, null];
        }

        $dot = strrpos($ability, '.');
        if ($dot !== false && ! empty($rules[substr($ability, 0, $dot)]['options'])) {
            return [substr($ability, 0, $dot), substr($ability, $dot + 1)];
        }

        return [null, null];
    }

    /**
     * @param list<array> $plan
     */
    private function apply(array $plan, bool $replace, array &$applied): void
    {
        $of = static fn (string $kind) => array_filter($plan, static fn (array $c) => $c['kind'] === $kind && ($c['action'] === 'create' || ($replace && $c['action'] === 'differs')));

        // Parents first: a rule refers to its parent by id, and the manifest keeps them by name.
        $pending = $of('rule');
        while ($pending !== []) {
            $progress = false;
            $waiting  = array_map(static fn (array $c) => $c['what'], array_filter($pending, static fn (array $c) => $c['action'] === 'create'));

            foreach ($pending as $i => $change) {
                $rule   = $change['rule'];
                $parent = $rule['parent'] ?? null;
                if ($parent !== null && $parent !== $change['what'] && in_array($parent, $waiting, true)) {
                    continue;
                }
                unset($pending[$i]);
                $progress = true;

                $fields = [
                    'title'     => $rule['title'] ?? null, 'description' => $rule['description'] ?? null, 'options' => $rule['options'] ?? null, 'resource' => $rule['resource'] ?? null,
                    'parent_id' => $parent === null ? 0 : (int) $this->existingRule($parent)?->getKey(), 'condition' => $rule['condition_tree'], 'origin' => $rule['origin'] ?? null,
                ];

                if ($change['action'] === 'create') {
                    $this->access->newRule($change['what'], $fields['title'], $fields['description'], $fields['parent_id'], $fields['options'], $fields['resource'], $fields['condition'], $fields['origin']);
                    $applied['rules']++;
                } else {
                    $this->existingRule($change['what'])->fill($fields)->save();
                    $applied['replaced']++;
                }
            }
            if (! $progress) {
                break;
            }
        }

        foreach ($of('owner') as $change) {
            $owner = $this->access->for(...$change['owner']);
            if ($change['action'] === 'create') {
                $owner->create($change['document']);
                $applied['owners']++;
            } else {
                $owner->record()->forceFill(['name' => $change['document']])->save();
                $applied['replaced']++;
            }
        }

        foreach ($of('permission') as $change) {
            $grant = $change['grant'];
            $owner = $this->access->for(...$grant['owner']);

            if ($change['action'] === 'differs') {
                $grant['permit'] ? $owner->removeAllow($grant['rule'], $grant['option']) : $owner->removeDeny($grant['rule'], $grant['option']);
                $applied['replaced']++;
            } else {
                $applied['permissions']++;
            }

            $grant['permit'] ? $owner->allow($grant['rule'], $grant['option'], $grant['tree']) : $owner->deny($grant['rule'], $grant['option'], $grant['tree']);
        }

        foreach ($of('inheritance') as $change) {
            [[$childType, $childId], [$parentType, $parentId]] = $change['link'];

            if ($this->knownType($childType) && $this->knownType($parentType) && $this->access->for($childType, $childId)->inheritFrom($parentType, $parentId)) {
                $applied['inheritance']++;
            }
        }
    }

    /**
     * Undoes what Exporter folded into one condition: the suffix ".self" went out as isAuthor(),
     * the condition of the rule went out in front of the condition of the permission. Without
     * this a round trip would keep every decision and still change what an administrator sees.
     *
     * @param  array<string, array>      $rules The picture of the future from plan().
     * @return array{0:string, 1:?array}
     */
    private function restore(string $rule, ?array $tree, array $rules): array
    {
        $parts = self::conjuncts($tree);

        if (($parts[0] ?? null) === ['is-author'] && isset($rules[$rule.'.self'])) {
            $rule .= '.self';
            array_shift($parts);
        }

        $ofRule = self::conjuncts($rules[$rule]['condition'] ?? null);
        if ($ofRule !== [] && array_slice($parts, 0, count($ofRule)) == $ofRule) {
            $parts = array_slice($parts, count($ofRule));
        }

        $tree = array_shift($parts);
        foreach ($parts as $part) {
            $tree = ['and', $tree, $part];
        }

        return [$rule, $tree];
    }

    private static function permissionKey(string $ownerKey, string $rule, ?string $option, bool $permit): string
    {
        return $ownerKey.'|'.$rule.'|'.$option.'|'.(int) $permit;
    }

    /**
     * @param list<?string> $text Title, description, options, resource, parent, condition, origin.
     */
    private static function ruleText(array $text): string
    {
        $labels = ['title', 'description', 'options', 'resource', 'parent', 'condition', 'origin'];

        return implode('; ', array_filter(array_map(static fn ($label, $value) => $value === null || $value === '' ? null : $label.': '.$value, $labels, $text + array_fill(0, 7, null))));
    }

    /**
     * @return list<array> Parts of a chain of "and", left to right.
     */
    private static function conjuncts(?array $tree): array
    {
        if ($tree === null) {
            return [];
        }

        if ($tree[0] === 'and') {
            return [...self::conjuncts($tree[1]), ...self::conjuncts($tree[2])];
        }

        // The condition of a rule is compiled from the manifest and may still say "!(a == b)".
        return [self::canonical($tree)];
    }

    /**
     * XACML has no "not equal". It writes "a != b" and "!(a == b)" the same way, and both come
     * back as the first. The two mean the same in three-valued logic, so a stored condition is
     * brought to that form before it is compared with the document. Without it the plan of an
     * unchanged export reported every "not order.locked" as a difference, and "replace" rewrote it.
     */
    private static function canonical(mixed $node): mixed
    {
        // Literals are left alone: a list of values may start with any word.
        if (! is_array($node) || ! is_string($node[0] ?? null) || $node[0] === 'val') {
            return $node;
        }

        if ($node[0] === 'not' && ($node[1][0] ?? null) === 'cmp' && $node[1][1] === '==') {
            $node    = $node[1];
            $node[1] = '!=';
        }

        return array_map(self::canonical(...), $node);
    }

    private function existingRule(string $name): ?RuleContract
    {
        return app(RuleContract::class)->newQuery()->where('guard_name', $name)->first();
    }

    /**
     * A rule created by the import has to name its model, or lists cannot be filtered by it.
     * The text was produced by Expressions a moment ago, so a path starts with an alias or with "user", "env".
     */
    private function aliasIn(string $when): ?string
    {
        foreach (array_keys($this->resources->all()) as $alias) {
            if (preg_match('/(?<![\w.\'])'.preg_quote($alias, '/').'\./', $when)) {
                return $alias;
            }
        }

        return null;
    }

    private function knownType(string $type): bool
    {
        return in_array($type, $this->types->all(), true);
    }

    /**
     * @return array{0:string, 1:string}
     */
    private static function splitKey(string $key): array
    {
        $at = strpos($key, ':');

        return $at === false ? [$key, ''] : [substr($key, 0, $at), substr($key, $at + 1)];
    }
}
