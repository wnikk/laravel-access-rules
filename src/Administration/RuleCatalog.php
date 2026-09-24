<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Administration;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Validator;
use Wnikk\LaravelAccessRules\Conditions\Cond;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Events\AccessChanged;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Models\RuleOrigin;
use Wnikk\LaravelAccessRules\Protected\Administration\AccessManager;
use Wnikk\LaravelAccessRules\Protected\Conditions\ConditionCompiler;
use Wnikk\LaravelAccessRules\Protected\Storage\PermissionCache;

/**
 * Holds the list of things that can be permitted at all, and matches an ability to its rule.
 *
 * Permissions point at rules by id, so a name that is not in this catalogue cannot be granted.
 * A typo in addPermission('ordres.view') fails at once instead of creating
 * a permission that no check asks about.
 *
 * The class belongs to the administration layer. Administration\Owners calls resolve() for
 * every grant and revoke, AccessManager calls create() and delete().
 *
 * Two kinds of callers write here. Code, migrations and seeders, uses create() and delete() and
 * is trusted with everything. An admin panel uses edit() and discard(), which look at the origin
 * of a rule first, see Models\RuleOrigin.
 *
 * Checks do not come here. Authorization\Permissions joins rules once while it compiles.
 */
final class RuleCatalog
{
    public function __construct(
        private ConditionCompiler $conditions,
        private PermissionCache $cache,
        private Dispatcher $events,
    ) {}

    /**
     * @param  array{guard_name:string, title?:?string, description?:?string, parent_id?:?int, options?:?string, resource?:?string, when?:mixed} $data "options" holds Laravel validation rules for the option, "resource" an alias from config access.resources, "when" a condition for every holder of the rule.
     * @return int|false                                                                                                                         Id of the rule.
     */
    public function create(array $data): int|false
    {
        $rule = $this->model();

        $rule->guard_name  = $data['guard_name'] ?? null;
        $rule->title       = $data['title'] ?? null;
        $rule->description = $data['description'] ?? null;
        $rule->options     = $data['options'] ?? null;
        $rule->resource    = $data['resource'] ?? null;
        $rule->origin      = self::origin($data['origin'] ?? null);
        $rule->condition   = $this->conditions->compile($data['when'] ?? null, $rule->resource);

        if (! empty($data['parent_id'])) {
            $rule->parent_id = $this->model()->newQuery()->findOrFail($data['parent_id'])->getKey();
        }

        if (! $rule->save()) {
            return false;
        }

        $this->events->dispatch(new AccessChanged(AccessChanged::RULE_CREATED, ['rule' => $rule->guard_name, 'resource' => $rule->resource, 'condition' => $rule->condition]));

        return $rule->getKey();
    }

    /**
     * A rule that somebody still holds is not deleted. Deleting takes permissions and
     * prohibitions along, and a prohibition that disappears widens access without a trace;
     * nothing brings the rows back afterwards. Version 2 answered this with a soft delete, see
     * Models\Rule. The caller takes permissions away first, or passes $force:
     * a migration that rolls back, a test that cleans up.
     *
     * @param bool $force Delete the rule together with every permission and prohibition for it.
     *
     * @throws AccessRulesException With code RULE_IN_USE.
     */
    public function delete(string $guardName, bool $force = false): bool
    {
        $rule = $this->model()->newQuery()->where('guard_name', $guardName)->first();
        if (! $rule) {
            return false;
        }

        $held = $force ? 0 : $rule->permission()->count();
        if ($held > 0) {
            throw new AccessRulesException('Rule "'.$guardName.'" is held by '.$held.' permission(s) or prohibition(s). Take them away first, or delete with force to remove them too.', AccessRulesException::RULE_IN_USE);
        }

        $deleted = (bool) $rule->delete();

        $this->events->dispatch(new AccessChanged(AccessChanged::RULE_DELETED, ['rule' => $guardName, 'force' => $force]));

        return $deleted;
    }

    /**
     * Deleting as an admin panel may do it: only rules that do not come with code, and never
     * with force. A panel that wants a rule gone shows who holds it and lets the administrator
     * take the permissions away first.
     *
     * @throws AccessRulesException With code RULE_MANAGED_BY_CODE or RULE_IN_USE.
     */
    public function discard(string $guardName): bool
    {
        $rule = $this->model()->newQuery()->where('guard_name', $guardName)->first();

        if ($rule?->origin->isManagedByCode()) {
            throw new AccessRulesException('Rule "'.$guardName.'" comes with code and is removed by a migration, together with the code that checks it.', AccessRulesException::RULE_MANAGED_BY_CODE);
        }

        return $this->delete($guardName);
    }

    /**
     * Editing as an admin panel may do it. Title, description, options and the place in the tree
     * are open for every rule. Name, resource and condition are open only for rules that do not
     * come with code: code asks for the name, passes a record of that resource and was written
     * with that condition in mind. The place in the tree is how a list reads, like the title,
     * with one exception: with config rule_tree_inheritance on, a permission for the parent
     * covers the children, so the place decides what a permission covers and stays with the code.
     *
     * Options are open although they are a contract too, because lists like "in:1,2,3" follow
     * data. Narrowing them leaves permissions for values that are no longer allowed; they keep
     * working, since an option is validated when it is granted, and "acr:lint" reports them.
     *
     * @param array{title?:?string, description?:?string, options?:?string, guard_name?:string, resource?:?string, when?:mixed, parent_id?:?int} $fields Only the keys that change.
     *
     * @throws AccessRulesException With code RULE_NOT_FOUND or RULE_MANAGED_BY_CODE.
     */
    public function edit(string $guardName, array $fields): bool
    {
        $rule = $this->model()->newQuery()->where('guard_name', $guardName)->first();
        if (! $rule) {
            throw new AccessRulesException('Rule "'.$guardName.'" is absent in the database.', AccessRulesException::RULE_NOT_FOUND);
        }

        $open   = config('access.rule_tree_inheritance') ? ['title', 'description', 'options'] : ['title', 'description', 'options', 'parent_id'];
        $closed = array_diff(array_keys($fields), $open);
        if ($closed !== [] && $rule->origin->isManagedByCode()) {
            $why = in_array('parent_id', $closed, true) && config('access.rule_tree_inheritance') ? ' With rule_tree_inheritance on, the place in the tree decides what a permission covers.' : '';

            throw new AccessRulesException('Rule "'.$guardName.'" comes with code: '.implode(', ', $closed).' cannot be changed from an admin panel.'.$why, AccessRulesException::RULE_MANAGED_BY_CODE);
        }

        if (array_key_exists('when', $fields) || array_key_exists('resource', $fields)) {
            $resource        = array_key_exists('resource', $fields) ? $fields['resource'] : $rule->resource;
            $when            = array_key_exists('when', $fields) ? $fields['when'] : $rule->condition;
            $rule->condition = $this->conditions->compile($when, $resource);
        }
        if (array_key_exists('parent_id', $fields)) {
            // The column is NOT NULL DEFAULT 0 since version 2; zero means "no parent".
            $rule->parent_id = empty($fields['parent_id']) ? 0 : $this->model()->newQuery()->findOrFail($fields['parent_id'])->getKey();
        }

        $saved = $rule->fill(array_intersect_key($fields, array_flip(['title', 'description', 'options', 'guard_name', 'resource'])))->save();

        $this->events->dispatch(new AccessChanged(AccessChanged::RULE_EDITED, ['rule' => $rule->guard_name, 'was' => $guardName, 'fields' => array_keys($fields)]));

        return $saved;
    }

    /**
     * A condition of a rule applies to everybody who holds the rule, on top of conditions of
     * their own permissions. Use it for facts about the record itself, like "not locked".
     */
    public function setCondition(string $guardName, string|Cond|array|null $when): bool
    {
        $rule            = $this->model()->newQuery()->where('guard_name', $guardName)->firstOrFail();
        $rule->condition = $this->conditions->compile($when, $rule->resource);

        return $rule->save();
    }

    /**
     * Tries the full name first and splits at the last dot second. The order matters: rules like
     * "posts.update.self" contain dots themselves, and splitting first would read ".self" as an option.
     *
     * @param string|null $option Receives the last segment when the split found the rule: "news.edit.2" gives rule "news.edit" and option "2".
     *
     * @throws AccessRulesException With code INVALID_OPTION.
     */
    public function resolve(string $ability, &$option = null): ?RuleContract
    {
        $query = $this->model()->newQuery();

        $rule = $query->clone()->where('guard_name', $ability)->first();

        if (! $rule && ($dot = strrpos($ability, '.'))) {
            $rule = $query->clone()->where('guard_name', substr($ability, 0, $dot))->first();
            if ($rule) {
                $option = substr($ability, $dot + 1);
            }
        }

        if ($rule) {
            $this->checkOption($rule, $option);
        }

        return $rule;
    }

    /**
     * The rule carries plain Laravel validation rules, for example "required|in:1,2,3". Reusing
     * the validator keeps options as expressive as form input without a format of our own.
     *
     * A rule without validation rules accepts no option at all. Otherwise "news.edit.anything"
     * becomes a grantable name that no check asks about.
     */
    public function checkOption(RuleContract $rule, $option): void
    {
        if ($rule->options) {
            if (Validator::make(['option' => $option], ['option' => $rule->options])->fails()) {
                throw new AccessRulesException('Specified option "'.$option.'" does not comply with the permissible rule.', AccessRulesException::INVALID_OPTION);
            }
        } elseif ($option !== null && $option !== '') {
            throw new AccessRulesException('Rule "'.$rule->guard_name.'" has no permissible option "'.$option.'". Before adding a permission, adjust rule option validator.', AccessRulesException::INVALID_OPTION);
        }
    }

    private static function origin(RuleOrigin|string|null $origin): RuleOrigin
    {
        return $origin instanceof RuleOrigin ? $origin : (RuleOrigin::tryFrom((string) $origin) ?? RuleOrigin::Code);
    }

    private function model(): RuleContract
    {
        return app(RuleContract::class);
    }
}
