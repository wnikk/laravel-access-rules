<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Authorization;

use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\Administration\TypeRegistry;
use Wnikk\LaravelAccessRules\Conditions\Evaluation\Context;
use Wnikk\LaravelAccessRules\Conditions\Evaluation\Evaluator;
use Wnikk\LaravelAccessRules\Conditions\ResourceRegistry;
use Wnikk\LaravelAccessRules\Conditions\Syntax\Printer;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;

/**
 * Tells why a check answers what it answers: which permission decided, where it came from,
 * what its condition read.
 *
 * "Why can't Ann see this order" is the question an access system gets asked most, and compiled
 * permissions cannot answer it: they drop everything that does not change a decision, origins first.
 * This class of the authorization layer reads permissions from the database again, keeps what
 * compiling throws away, and runs them through DecisionPoint::firstApplicable(), the very loop
 * that makes real decisions. It has no decision logic of its own, so it cannot disagree with a check.
 *
 * A permitted check never calls this class. GateHook and OwnerAccess turn to it on the way to a
 * refusal: to remember the name of the ability, and in debug mode to explain. The rest of the
 * time it answers OwnerAccess::explain() and "artisan acr:explain".
 *
 * It also decides once more from the cache and compares. When the two answers differ, the cache is
 * stale, which is the one failure no amount of staring at rules in the database reveals.
 */
class Explainer
{
    /**
     * The ability of the last refusal of this request, kept in any mode: it costs one assignment.
     * Version 2 exposed it as AccessRules::getLastDisallowPermission() from a static property, and
     * error pages of existing projects read it. Here it lives as long as the request.
     */
    public ?string $lastDenied = null;

    /** Null until somebody asks: config is read on first use, and enable() wins over it. */
    private ?bool $enabled = null;

    /** @var list<array> Explanations of refusals of this request, in the order they happened. */
    private array $denials = [];

    /** @var list<array> What allowedTo() added to queries of this request. */
    private array $lists = [];

    public function __construct(
        private Permissions $permissions,
        private DecisionPoint $decisions,
        private TypeRegistry $types,
        private ResourceRegistry $resources,
    ) {}

    /**
     * @param array $args Arguments of the check, as for DecisionPoint::decide().
     * @return array{
     *     ability:string, asked:string, decision:?bool, from_cache:?bool, stale_cache:bool,
     *     owner:array{type:string, id:string|int|null, exists:bool},
     *     entries:list<array{effect:string, condition:?string, result:mixed, decisive:bool, error:?string, from:string, own:bool, rule:string, option:?string, via:string}>,
     *     values:array<string, mixed>
     * } "decision" comes from the database, "from_cache" is what a real check answers right now.
     */
    public function explain(int $type, string|int|null $id, ?Model $subject, string $ability, array $args = []): array
    {
        $record  = $args[0] ?? null;
        $entries = $this->permissions->trace($type, $id, $ability);
        $facts   = $this->permissions->build($type, $id);

        $trace    = [];
        $decision = $this->decisions->firstApplicable($entries, $facts, $subject, $ability, $args, $trace);
        $cached   = $this->decisions->decide($type, $id, $subject, $ability, $args);

        $alias   = $record instanceof Model ? $this->resources->alias($record::class) : (is_string($record) ? $this->resources->alias($record) : null);
        $context = new Context($subject, $record, $ability, $facts);
        $owners  = $this->labels(array_column(array_column($entries, 3), 'owner'));
        $values  = [];
        $lines   = [];

        foreach ($entries as $i => [$permit, $condition, , $origin]) {
            $result = $trace[$i]['result'] ?? 'not reached';

            // Values are shown for conditions that really ran. Reading them for the rest could load
            // relations that the real check never touches, and the explanation would lie about its cost.
            if ($condition !== null && ! in_array($result, ['skipped', 'not reached', 'general'], true)) {
                $values += $this->read($condition, $context, $alias ?? 'resource');
            }

            $lines[] = [
                'effect'    => $permit ? 'permit' : 'prohibit',
                'condition' => $condition === null ? null : Printer::print($condition, $alias ?? 'resource'),
                'result'    => $result,
                'decisive'  => $trace[$i]['decisive'] ?? false,
                'error'     => $trace[$i]['error'] ?? null,
                'from'      => $owners[$origin['owner']] ?? '#'.$origin['owner'],
                'own'       => $origin['own'],
                'rule'      => $origin['rule'],
                'option'    => $origin['option'],
                'via'       => $origin['via'],
            ];
        }

        return [
            'ability'     => $ability,
            'asked'       => $record instanceof Model ? 'record' : (is_string($record) ? 'class' : 'nothing'),
            'decision'    => $decision,
            'from_cache'  => $cached,
            'stale_cache' => $decision !== $cached,
            'owner'       => ['type' => $this->types->name($type) ?? (string) $type, 'id' => $id, 'exists' => $facts['owner'] !== null],
            'entries'     => $lines,
            'values'      => $values,
        ];
    }

    /**
     * Debug mode: every refusal carries its explanation and every filtered list is written down.
     *
     * The intended use is a support session. A user complains, an administrator signs in as that
     * user and ticks "debug": a middleware of the application calls Access::debug(), and from then
     * on a 403 says which permission refused and a short list says which conditions narrowed it.
     * Version 2 had a fragment of it, getLastDisallowPermission(), that named the ability.
     *
     * It is off by default and should stay off for ordinary users. An explanation shows rules of
     * other owners, their conditions and values of attributes, which is more than a 403 may tell a stranger.
     */
    public function enabled(): bool
    {
        return $this->enabled ??= (bool) config('access.debug', false);
    }

    public function enable(bool $on = true): void
    {
        $this->enabled = $on;
    }

    /**
     * Explains a refusal, keeps it for log() and returns it as text for the message of the 403.
     * Runs several queries, which is why GateHook calls it only in debug mode.
     */
    public function denied(?int $type, string|int|null $id, ?Model $subject, string $ability, array $args): string
    {
        if ($type === null) {
            $report = ['ability' => $ability, 'asked' => 'nothing', 'decision' => null, 'owner' => null, 'entries' => [], 'values' => [], 'stale_cache' => false];
        } else {
            $report = $this->explain($type, $id, $subject, $ability, $args);
        }

        $this->denials[] = $report;

        return $this->describe($report);
    }

    /**
     * Writes down what allowedTo() did to a query: the permissions it used, as text, and the SQL they became.
     * "Why is the list shorter than I expect" is answered by reading those conditions; "why is this
     * very record missing" by explain() for that record.
     *
     * @param array{sql:?string, bindings:list<mixed>, outcome:string} $applied What DecisionPoint::constrain() returned.
     */
    public function listed(int $type, string|int|null $id, string $ability, string $model, array $applied): void
    {
        $alias = $this->resources->alias($model) ?? 'resource';

        $this->lists[] = [
            'ability'  => $ability,
            'model'    => $model,
            'owner'    => ['type' => $this->types->name($type) ?? (string) $type, 'id' => $id],
            'outcome'  => $applied['outcome'],
            'sql'      => $applied['sql'],
            'bindings' => $applied['bindings'],
            'entries'  => array_map(fn (array $entry) => [
                'effect'    => $entry[0] ? 'permit' : 'prohibit',
                'condition' => $entry[1] === null ? null : Printer::print($entry[1], $alias),
                'own'       => $entry[3]['own'],
                'rule'      => $entry[3]['rule'],
                'via'       => $entry[3]['via'],
            ], $this->permissions->trace($type, $id, $ability)),
        ];
    }

    /**
     * @return array{denials:list<array>, lists:list<array>} Everything debug mode has collected during this request.
     */
    public function log(): array
    {
        return ['denials' => $this->denials, 'lists' => $this->lists];
    }

    /**
     * An explanation as lines of text: for the message of a refusal and for "artisan acr:explain".
     * One format for both, so what support reads in a 403 is what they get from the console.
     */
    public function describe(array $report): string
    {
        $lines = [sprintf(
            '%s%s, asked about %s: %s',
            $report['ability'],
            $report['owner'] === null ? '' : ' for '.class_basename($report['owner']['type']).' '.$report['owner']['id'],
            ['record' => 'a record', 'class' => 'records in general', 'nothing' => 'no record'][$report['asked']],
            match ($report['decision']) {
                true  => 'PERMITTED',
                false => 'PROHIBITED',
                null  => 'NOTHING IS SAID, so access is denied unless a Laravel policy permits it',
            }
        )];

        if ($report['owner'] === null) {
            $lines[] = 'The request has no owner: nobody is signed in and config access.guest is empty, or the class of the user is not in config access.owner_types.';
        } elseif (! $report['owner']['exists']) {
            $lines[] = 'The owner has no record in the database, so it holds and inherits nothing.';
        } elseif ($report['entries'] === []) {
            $lines[] = 'Neither the owner nor anybody it inherits from holds a permission for this ability.';
        }

        foreach ($report['entries'] as $i => $entry) {
            $lines[] = sprintf(
                '%s %s, %s: %s, rule %s%s%s -> %s',
                $entry['decisive'] ? '=>' : str_pad((string) ($i + 1), 2, ' ', STR_PAD_LEFT),
                $entry['effect'],
                $entry['own'] ? 'own' : 'inherited',
                $entry['from'],
                $entry['rule'].($entry['option'] === null ? '' : '.'.$entry['option']),
                $entry['via'] === 'direct' ? '' : ' (via '.$entry['via'].')',
                $entry['condition'] === null ? '' : ', when '.$entry['condition'],
                is_string($entry['result']) ? $entry['result'].($entry['error'] ? ': '.$entry['error'] : '') : var_export($entry['result'], true)
            );
        }

        foreach ($report['values'] as $name => $value) {
            $lines[] = '   '.$name.' = '.json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if ($report['stale_cache']) {
            $lines[] = 'The cache answers differently: '.var_export($report['from_cache'], true).'. Run "php artisan acr:cache:clear" and find out what changed permissions past the package.';
        }

        return implode("\n", $lines);
    }

    /**
     * Values of every attribute and aggregate a condition mentions, by the text they have in it.
     * Each leaf goes through Evaluator on its own, so the explanation shows what the check saw.
     *
     * @return array<string, mixed>
     */
    private function read(array $node, Context $context, string $alias): array
    {
        if (in_array($node[0], ['attr', 'res', 'agg', 'call', 'is-author'], true)) {
            try {
                return [Printer::print($node, $alias) => Evaluator::evaluate($node, $context)];
            } catch (\Throwable $e) {
                return [Printer::print($node, $alias) => 'error: '.$e->getMessage()];
            }
        }

        $values = [];
        foreach (array_slice($node, 1) as $child) {
            if (is_array($child) && isset($child[0]) && is_string($child[0]) && $child[0] !== 'val') {
                $values += $this->read($child, $context, $alias);
            }
        }

        return $values;
    }

    /**
     * @param  list<int>          $ids Record ids of owners.
     * @return array<int, string> "Role manager (Managers)"
     */
    private function labels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return app(OwnerContract::class)->newQuery()->whereIn('id', array_unique($ids))->get()
            ->mapWithKeys(fn ($owner) => [(int) $owner->id => trim(
                class_basename($this->types->name((int) $owner->type) ?? '#'.$owner->type).' '.$owner->original_id.($owner->name ? ' ('.$owner->name.')' : '')
            )])->all();
    }
}
