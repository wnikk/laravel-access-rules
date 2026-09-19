<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Administration;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Validator;
use Wnikk\LaravelAccessRules\Conditions\Cond;
use Wnikk\LaravelAccessRules\Conditions\ConditionCompiler;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Events\AccessChanged;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Storage\PermissionCache;

/**
 * Holds the list of things that can be permitted at all, and matches an ability to its rule.
 *
 * Permissions point at rules by id, so a name that is not in this catalogue cannot be granted.
 * That is deliberate: a typo in addPermission('ordres.view') fails at once instead of creating
 * a permission that no check ever asks about.
 *
 * The class belongs to the administration layer. Administration\Owners calls resolve() for
 * every grant and revoke, AccessManager calls create() and delete().
 *
 * Checks do not come here. Authorization\Permissions joins rules once while it compiles, and
 * a rule that is soft deleted drops out of that join without any code in this class.
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
     * Soft delete is the default because it is reversible: permissions stay in place and come
     * back with the rule. The model drops cached permissions on delete and on restore, so callers
     * of this method do not.
     */
    public function delete(string $guardName, bool $force = false): bool
    {
        $rule = $this->model()->newQuery()->where('guard_name', $guardName)->first();
        if (! $rule) {
            return false;
        }

        $deleted = (bool) ($force ? $rule->forceDelete() : $rule->delete());

        $this->events->dispatch(new AccessChanged(AccessChanged::RULE_DELETED, ['rule' => $guardName, 'force' => $force]));

        return $deleted;
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
     * becomes a grantable name that no check will ever ask about.
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

    private function model(): RuleContract
    {
        return app(RuleContract::class);
    }
}
