<?php

namespace App\Http\Middleware;

use App\Models\Budget;
use App\Models\Category;
use Diglactic\Breadcrumbs\Breadcrumbs;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'environment' => app()->environment(),
            // The <meta name="csrf-token"> tag is only written on a full page
            // load, so it goes stale the moment the session token is
            // regenerated (passkey login, magic link, logout) and the app keeps
            // navigating client-side. Ship the live token on every Inertia
            // response so the client can re-sync — see resources/js/app.js.
            // always(): partial reloads (only: [...]) must carry it too, or
            // Inertia keeps the previous page's stale value and app.js writes
            // it back over a token the 419 retry just refreshed.
            'csrf_token' => Inertia::always(fn () => $request->session()->token()),
            'auth' => [
                'user' => $request->user(),
            ],
            'activeBudget' => $this->getActiveBudget($request),
            'userBudgets' => $this->getUserBudgets($request),
            'budgetAccess' => $this->getBudgetAccess($request),
            'breadcrumbs' => $this->getBreadcrumbs($request),
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'error' => fn () => $request->session()->get('error'),
                'status' => fn () => $request->session()->get('status'),
                'show_passkey_prompt' => fn () => $request->session()->get('show_passkey_prompt'),
            ],
        ];
    }

    /**
     * Get the active budget for the current user.
     */
    protected function getActiveBudget(Request $request): ?array
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        $activeBudget = $user->getActiveBudget();

        if (! $activeBudget) {
            return null;
        }

        // Load accounts for the active budget to support quick actions
        $activeBudget->load('accounts');

        return [
            'id' => $activeBudget->id,
            'name' => $activeBudget->name,
            'description' => $activeBudget->description,
            'color' => $activeBudget->color,
            'role' => $activeBudget->roleFor($user)?->value,
            'starting_balance' => $activeBudget->starting_balance,
            'accounts' => $activeBudget->accounts->map(fn ($account) => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
            ])->toArray(),
            // For the quick-add category picker.
            'categories' => Category::optionsForBudget($activeBudget->id),
        ];
    }

    /**
     * Get all accessible budgets for the current user.
     */
    protected function getUserBudgets(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return [];
        }

        $budgets = $user->accessibleBudgets();
        $roles = $user->budgetRoles();

        return $budgets->map(fn ($budget) => [
            'id' => $budget->id,
            'name' => $budget->name,
            'description' => $budget->description,
            'color' => $budget->color,
            'role' => ($roles[$budget->id] ?? null)?->value,
            'shared' => (int) $budget->user_id !== (int) $user->id,
        ])->toArray();
    }

    /**
     * The current user's role and abilities on the budget in the current route,
     * so pages can hide controls the user can't use. Server-side policies are
     * the real enforcement; this is presentation only. Null off budget routes.
     *
     * @return array{budget_id: int, role: string|null, can: array<string, bool>}|null
     */
    protected function getBudgetAccess(Request $request): ?array
    {
        $user = $request->user();
        $budget = $request->route()?->parameter('budget');

        if (! $user || ! $budget instanceof Budget) {
            return null;
        }

        $role = $budget->roleFor($user);

        return [
            'budget_id' => $budget->id,
            'role' => $role?->value,
            'can' => [
                'edit' => $role?->canEdit() ?? false,
                'manage' => $role?->isOwner() ?? false,
                'manage_connections' => $role?->isOwner() ?? false,
            ],
        ];
    }

    /**
     * Get breadcrumbs for the current route.
     */
    protected function getBreadcrumbs(Request $request): array
    {
        $routeName = $request->route()?->getName();

        if (! $routeName) {
            return [];
        }

        try {
            $breadcrumbs = Breadcrumbs::generate($routeName, ...array_values($request->route()->parameters()));

            return $breadcrumbs->map(function ($breadcrumb) {
                return [
                    'title' => $breadcrumb->title,
                    'url' => $breadcrumb->url,
                ];
            })->toArray();
        } catch (\Exception $e) {
            // If breadcrumbs don't exist for this route, return empty array
            return [];
        }
    }
}
