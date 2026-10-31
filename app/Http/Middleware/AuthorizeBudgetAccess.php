<?php

namespace App\Http\Middleware;

use App\Models\Budget;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline authorization for every route bound to a {budget}:
 *
 * - safe methods (GET/HEAD/OPTIONS) require the BudgetPolicy `view` ability (any member),
 *   except create/edit form pages, which need `update` like the write they lead to
 * - every other method requires `update` (editor or owner)
 *
 * This guarantees that viewers can never mutate budget data, even through an
 * action that forgot its own check. Controllers still authorize stricter,
 * owner-only abilities (settings, deletion, sharing, bank connections) on top.
 * Routes that a viewer must be able to POST to (e.g. leaving a budget) opt out
 * with ->withoutMiddleware(AuthorizeBudgetAccess::class) and authorize themselves.
 */
class AuthorizeBudgetAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $budget = $request->route('budget');

        if ($budget instanceof Budget && $request->user()) {
            Gate::authorize($this->requiresEdit($request) ? 'update' : 'view', $budget);
        }

        return $next($request);
    }

    /**
     * Writes need edit access, and so do the create/edit form pages that only
     * exist to make a write (a viewer would just hit a 403 on save).
     */
    private function requiresEdit(Request $request): bool
    {
        if (! $request->isMethodSafe()) {
            return true;
        }

        $name = (string) $request->route()?->getName();

        return str_ends_with($name, '.create') || str_ends_with($name, '.edit');
    }
}
