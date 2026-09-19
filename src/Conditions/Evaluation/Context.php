<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Conditions\Evaluation;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context as LaravelContext;

/**
 * Supplies the values a condition asks about: the subject, the record, the action, the environment.
 *
 * A condition may mention ten attributes and read two before it is decided. Every value is
 * looked up when the evaluator asks for it and remembered for the rest of the check, so
 * "env.ip" costs nothing in conditions that never mention it, and "user.department.code"
 * loads the relation once.
 *
 * DecisionPoint creates one Context per check, SqlCompiler gets one to compute the parts of
 * a condition that do not depend on the record. The class reads and never decides anything.
 */
final class Context
{
    /** @var array<string, mixed> */
    private array $memo = [];

    private ?CarbonImmutable $now = null;

    /**
     * @param Model|null                                         $subject  Who is checked. Null for a guest and for owners without a model, such as roles.
     * @param mixed                                              $resource What the ability is checked against: a model, a class name, or nothing.
     * @param array{roles?:list<int>, tenants?:list<int|string>} $facts    Compiled permissions of the subject. They already hold its roles and tenants, so "user.tenant" needs no query.
     */
    public function __construct(
        public readonly ?Model $subject,
        public private(set) mixed $resource = null,
        public readonly string $ability = '',
        private readonly array $facts = [],
    ) {}

    public function record(): ?Model
    {
        return $this->resource instanceof Model ? $this->resource : null;
    }

    /**
     * A copy that looks at another record, for rows of a relation inside an aggregate filter.
     * The copy keeps the memory of subject and environment values, which do not change per row.
     */
    public function forRecord(Model $record): self
    {
        $copy           = clone $this;
        $copy->resource = $record;

        return $copy;
    }

    /**
     * One moment for the whole check. A condition like "paid_at >= monthStart(-1) && paid_at < monthStart(0)"
     * evaluated across midnight of the first of a month would otherwise see two different months.
     */
    public function now(): CarbonImmutable
    {
        return $this->now ??= CarbonImmutable::now();
    }

    public function attr(string $category, string $path): mixed
    {
        $key = $category.'.'.$path;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $provider = config('access.attributes')[$key] ?? null;

        $value = match (true) {
            $provider !== null      => (is_string($provider) ? app($provider) : $provider)($this),
            $category === 'subject' => $this->subjectAttr($path),
            $category === 'env'     => $this->envAttr($path),
            $category === 'action'  => $path === 'id' ? $this->ability : null,
            default                 => null,
        };

        return $this->memo[$key] = self::scalar($value);
    }

    /**
     * Conditions compare plain values. Dates become "Y-m-d H:i:s" text, the form databases
     * compare them in, and backed enums become their value, which is what the column stores.
     */
    public static function scalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof \BackedEnum       => $value->value,
            default                             => $value,
        };
    }

    private function subjectAttr(string $path): mixed
    {
        return match ($path) {
            'roles'  => $this->facts['roles'] ?? [],
            'tenant' => $this->facts['tenants'] ?? [],
            'guest'  => $this->subject === null,
            'id'     => $this->subject?->getKey(),
            'type'   => $this->subject?->getMorphClass(),
            default  => $this->subject === null ? null : data_get($this->subject, $path),
        };
    }

    private function envAttr(string $path): mixed
    {
        return match ($path) {
            'now'     => $this->now()->format('Y-m-d H:i:s'),
            'today'   => $this->now()->format('Y-m-d'),
            'time'    => $this->now()->format('H:i:s'),
            'hour'    => $this->now()->hour,
            'weekday' => $this->now()->isoWeekday(),
            'ip'      => app()->bound('request') ? app('request')->ip() : null,
            'app'     => app()->environment(),
            default   => LaravelContext::get($path),
        };
    }
}
