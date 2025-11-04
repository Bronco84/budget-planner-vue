<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Diglactic\Breadcrumbs\Breadcrumbs;

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
            'auth' => [
                'user' => $request->user(),
            ],
            'activeBudget' => $this->getActiveBudget($request),
            'breadcrumbs' => $this->getBreadcrumbs($request),
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Get the active budget for the current user.
     */
    protected function getActiveBudget(Request $request): ?array
    {
        $user = $request->user();

        if (!$user) {
            return null;
        }

        $activeBudget = $user->getActiveBudget();

        if (!$activeBudget) {
            return null;
        }

        // Load accounts for the active budget to support quick actions
        $activeBudget->load('accounts');

        return [
            'id' => $activeBudget->id,
            'name' => $activeBudget->name,
            'description' => $activeBudget->description,
            'starting_balance' => $activeBudget->starting_balance,
            'accounts' => $activeBudget->accounts->map(fn($account) => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
            ])->toArray(),
        ];
    }

    /**
     * Get breadcrumbs for the current route.
     */
    protected function getBreadcrumbs(Request $request): array
    {
        $routeName = $request->route()?->getName();

        if (!$routeName) {
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
