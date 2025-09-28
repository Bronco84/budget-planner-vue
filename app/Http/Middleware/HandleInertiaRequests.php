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
            'breadcrumbs' => $this->getBreadcrumbs($request),
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'error' => fn () => $request->session()->get('error'),
            ],
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
