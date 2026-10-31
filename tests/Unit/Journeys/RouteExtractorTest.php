<?php

use Tooling\Journeys\Analysis\PhpAnalyzer;
use Tooling\Journeys\Support\Glob;

function extractRoutes(string $code, string $prefix = ''): array
{
    $facts = (new PhpAnalyzer)->analyze('routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\nuse App\\Http\\Controllers\\PostController;\n\n".$code, ['prefix' => $prefix, 'name' => '']);

    return $facts['routes'];
}

test('group prefixes and name prefixes compose', function () {
    $routes = extractRoutes(<<<'PHP'
        Route::prefix('admin')->name('admin.')->middleware('auth')->group(function () {
            Route::prefix('posts')->name('posts.')->group(function () {
                Route::get('/{post}', [PostController::class, 'show'])->name('show');
            });
        });
        PHP);

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['id'])->toBe('admin.posts.show')
        ->and($routes[0]['uri'])->toBe('admin/posts/{post}')
        ->and($routes[0]['middleware'])->toBe(['auth'])
        ->and($routes[0]['action'])->toBe(['type' => 'controller', 'class' => 'App\Http\Controllers\PostController', 'method' => 'show']);
});

test('nested resources expand to named actions with singular parameters', function () {
    $routes = extractRoutes("Route::resource('blogs.posts', PostController::class)->except(['create', 'edit']);");

    expect(array_column($routes, 'id'))->toBe(['blogs.posts.index', 'blogs.posts.store', 'blogs.posts.show', 'blogs.posts.update', 'blogs.posts.destroy'])
        ->and($routes[2]['uri'])->toBe('blogs/{blog}/posts/{post}')
        ->and($routes[3]['methods'])->toBe(['PUT', 'PATCH']);
});

test('unnamed closure routes get a METHOD /uri id and closure facts', function () {
    $routes = extractRoutes("Route::get('/up', function () { return redirect()->route('home'); });");

    expect($routes[0]['id'])->toBe('GET /up')
        ->and($routes[0]['action']['type'])->toBe('closure')
        ->and($routes[0]['action']['closure']['route_refs'])->toBe(['home']);
});

test('framework-level file prefixes apply (routes/api.php is loaded under api/)', function () {
    $routes = extractRoutes("Route::get('/user', fn () => null);", 'api');

    expect($routes[0]['uri'])->toBe('api/user')
        ->and($routes[0]['id'])->toBe('GET /api/user');
});

test('route statements carry their line ranges for hunk attribution', function () {
    $routes = extractRoutes("Route::get('/a', [PostController::class, 'a'])\n    ->name('a');");

    expect($routes[0]['lines'])->toBe([6, 7]);
});

test('glob patterns follow gitignore-style semantics', function () {
    expect(Glob::matches('app/Http/Middleware/**', 'app/Http/Middleware/Auth.php'))->toBeTrue()
        ->and(Glob::matches('app/*.php', 'app/Http/Kernel.php'))->toBeFalse()
        ->and(Glob::matches('**/*.md', 'README.md'))->toBeTrue()
        ->and(Glob::matches('**/*.md', 'docs/a/b.md'))->toBeTrue()
        ->and(Glob::matches('resources/js/', 'resources/js/app.js'))->toBeTrue();
});
