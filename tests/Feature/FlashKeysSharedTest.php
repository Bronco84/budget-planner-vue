<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Symfony\Component\Finder\Finder;

/*
 * A redirect that flashes an outcome under a key HandleInertiaRequests doesn't
 * share (e.g. 'success', 'info', 'warning') never reaches the page, so the user
 * gets no feedback. Every flashed key in app/ must be one of the shared flash keys.
 */
test('every key the app flashes is shared to the client', function () {
    $request = Request::create('/');
    $request->setLaravelSession(new Store('test', new ArraySessionHandler(1)));

    $shared = array_keys((new HandleInertiaRequests)->share($request)['flash']);

    $unshared = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $source = $file->getContents();

        // ->with('key', <value>) on a redirect (an Eloquent ->with('relation', fn ...)
        // takes a closure, so skip those), and session()->flash('key', ...).
        preg_match_all("/->with\\(\\s*'([A-Za-z_]+)'\\s*,\\s*+(?!fn\\b|function\\b|static\\b)/", $source, $with);
        preg_match_all("/->flash\\(\\s*'([A-Za-z_]+)'/", $source, $flash);

        foreach (array_merge($with[1], $flash[1]) as $key) {
            if (! in_array($key, $shared, true)) {
                $unshared[] = $file->getRelativePathname().": '{$key}'";
            }
        }
    }

    expect($shared)->toContain('message', 'error')
        ->and($unshared)->toBe([]);
});
