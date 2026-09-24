<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Traits;

use BackedEnum;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\Exceptions\UntranslatableConditionException;
use Wnikk\LaravelAccessRules\Protected\Administration\Owners;
use Wnikk\LaravelAccessRules\Protected\Authorization\DecisionPoint;
use Wnikk\LaravelAccessRules\Protected\Authorization\Explainer;
use Wnikk\LaravelAccessRules\Protected\Authorization\GateHook;

/**
 * Adds allowedTo() to models that permissions with conditions are about.
 *
 *     Order::query()->where('status', 'new')->allowedTo('orders.view')->paginate();
 *     Order::query()->allowedTo('orders.view', $user)->get();
 *
 * The scope narrows the query inside the database. The obvious alternative, loading the rows
 * and asking can() for each, breaks pagination, since a page of fifty shrinks to the thirty
 * that pass, and turns a list of ten thousand into ten thousand checks.
 *
 * It is a separate trait because it goes on records (Order), while HasPermissions goes on
 * owners (User). A model can be both.
 */
trait HasAccessScope
{
    /**
     * @param mixed $owner Model of an owner, OwnerAccess, or AccessRules with a selected owner. Null means the signed in user, and the guest owner from config when nobody is signed in.
     *
     * @throws UntranslatableConditionException When a condition applies a custom function to columns of the record.
     */
    #[Scope]
    protected function allowedTo(Builder $query, string|BackedEnum $ability, mixed $owner = null): void
    {
        $owner ??= auth()->user();

        $address = $owner === null
            ? app(GateHook::class)->owner(null)
            : ($owner instanceof Model ? app(Owners::class)->addressOfModel($owner) : app(Owners::class)->address($owner));

        // No owner to check as: the list is empty. An unfiltered list would show every record.
        if ($address === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        $ability = $ability instanceof BackedEnum ? $ability->value : $ability;
        $applied = app(DecisionPoint::class)->constrain($query, $address[0], $address[1], $owner instanceof Model ? $owner : null, $ability);

        // A query runs right after this, so one more container lookup is nothing next to it.
        if (app(Explainer::class)->enabled()) {
            app(Explainer::class)->listed($address[0], $address[1], $ability, $query->getModel()::class, $applied);
        }
    }
}
