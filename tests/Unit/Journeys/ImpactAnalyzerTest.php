<?php

use Tests\Unit\Journeys\FixtureRepo;
use Tooling\Journeys\Impact\ChangeSet;

$needsNode = fn () => ! FixtureRepo::nodeAvailable() && ! getenv('JOURNEYS_REQUIRE_NODE');

beforeEach(function () {
    $this->repo = FixtureRepo::create();
});

test('a change to a journey controller is a direct impact', function () {
    $this->repo->replace('app/Http/Controllers/OrderController.php', '->all()]', "->all(), 'x' => 1]");

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::journeyIds($report))->toBe(['ORDER-001'])
        ->and(FixtureRepo::journey($report, 'ORDER-001')['impact'])->toBe('direct')
        ->and(array_column($report['tests'], 'path'))->toContain('tests/Feature/OrderTest.php')
        ->and($report['full_suite_recommended'])->toBeFalse();
});

test('a service two hops away is a transitive impact with an explained path', function () {
    $this->repo->replace('app/Services/PriceCalculator.php', 'return 0;', 'return 1;');

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));
    $order = FixtureRepo::journey($report, 'ORDER-001');

    expect(FixtureRepo::journeyIds($report))->toBe(['ORDER-001'])
        ->and($order['impact'])->toBe('transitive')
        ->and($order['reasons'][0]['path'])->toBe([
            'route:app.orders.index',
            'method:App\Http\Controllers\OrderController::index',
            'app/Services/OrderService.php',
            'app/Services/PriceCalculator.php',
        ]);
});

test('tests are selected through the graph even when no journey maps them', function () {
    $this->repo->replace('app/Services/InvoiceService.php', 'return [];', 'return [1];');

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::journeyIds($report))->toBe(['INV-001'])
        ->and($report['tests'])->toContain(['path' => 'tests/Feature/InvoiceTest.php', 'reason' => 'reaches app/Services/InvoiceService.php']);
});

test('a nested Vue component change reaches the page that imports it', function () {
    $this->repo->replace('resources/js/Components/Money.vue', '{{ value }}', '${{ value }}');

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::journeyIds($report))->toBe(['ORDER-001'])
        ->and(FixtureRepo::journey($report, 'ORDER-001')['reasons'][0]['path'])->toContain('resources/js/Components/OrderTable.vue');
})->skip($needsNode, 'node and node_modules are required for Vue analysis');

test('a shared layout affects every journey that uses it but not others', function () {
    $this->repo->replace('resources/js/Layouts/AppLayout.vue', '<main>', '<main class="x">');

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::journeyIds($report))->toBe(['INV-001', 'ORDER-001']);
})->skip($needsNode, 'node and node_modules are required for Vue analysis');

test('shared infrastructure affects all journeys and recommends the full suite', function () {
    $this->repo->replace('bootstrap/app.php', 'return [];', 'return [1];');

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::journeyIds($report))->toBe(['AUTH-001', 'INV-001', 'ORDER-001'])
        ->and(FixtureRepo::classification($report, 'bootstrap/app.php'))->toBe('shared_infrastructure')
        ->and($report['full_suite_recommended'])->toBeTrue();
});

test('frontend-scoped shared files flag only journeys with frontend code and do not force the full suite', function () {
    $this->repo->replace('resources/js/app.js', "'./bootstrap'", "'./bootstrap.js'");

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::journeyIds($report))->toBe(['AUTH-001', 'INV-001', 'ORDER-001'])
        ->and($report['full_suite_recommended'])->toBeFalse();
})->skip($needsNode, 'node and node_modules are required for Vue analysis');

test('an added file that nothing references is uncertain, never silently ignored', function () {
    $this->repo->write('app/Services/Orphan.php', "<?php\n\nnamespace App\\Services;\n\nclass Orphan\n{\n}\n");

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::classification($report, 'app/Services/Orphan.php'))->toBe('unreached')
        ->and($report['uncertain'])->not->toBeEmpty()
        ->and($report['full_suite_recommended'])->toBeTrue();
});

test('an added file wired into a journey is mapped through its new dependant', function () {
    $this->repo->write('app/Services/Discounts.php', "<?php\n\nnamespace App\\Services;\n\nclass Discounts\n{\n}\n");
    $this->repo->replace('app/Services/PriceCalculator.php', 'return 0;', 'new Discounts; return 0;');

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::classification($report, 'app/Services/Discounts.php'))->toBe('mapped')
        ->and(FixtureRepo::journeyIds($report))->toBe(['ORDER-001'])
        ->and($report['uncertain'])->toBeEmpty();
});

test('a deleted file is resolved through the base snapshot graph', function () {
    $this->repo->delete('app/Services/PriceCalculator.php');

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::journeyIds($report))->toBe(['ORDER-001'])
        ->and($report['files'][0]['status'])->toBe('D')
        ->and(FixtureRepo::classification($report, 'app/Services/PriceCalculator.php'))->toBe('mapped');
});

test('a renamed file is traced through both its old and new paths', function () {
    mkdir($this->repo->root.'/app/Services/Billing');
    $this->repo->git('mv', 'app/Services/InvoiceService.php', 'app/Services/Billing/InvoiceService.php');
    $this->repo->commit('wip');
    $base = trim($this->repo->git('rev-parse', 'HEAD~1'));

    $report = $this->repo->impact(ChangeSet::range($this->repo->git, $base));
    $file = $report['files'][0];

    expect($file['status'])->toBe('R')
        ->and($file['old_path'])->toBe('app/Services/InvoiceService.php')
        ->and($file['path'])->toBe('app/Services/Billing/InvoiceService.php')
        ->and(FixtureRepo::journeyIds($report))->toBe(['INV-001']);
});

test('staged and unstaged changes are analysed separately', function () {
    $this->repo->replace('app/Services/PriceCalculator.php', 'return 0;', 'return 2;');
    $this->repo->stage('app/Services/PriceCalculator.php');
    $this->repo->replace('app/Services/InvoiceService.php', 'return [];', 'return [2];');

    $staged = $this->repo->impact(ChangeSet::staged($this->repo->git));
    $unstaged = $this->repo->impact(ChangeSet::unstaged($this->repo->git));
    $working = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(array_column($staged['files'], 'path'))->toBe(['app/Services/PriceCalculator.php'])
        ->and(FixtureRepo::journeyIds($staged))->toBe(['ORDER-001'])
        ->and(FixtureRepo::journeyIds($unstaged))->toBe(['INV-001'])
        ->and(FixtureRepo::journeyIds($working))->toBe(['INV-001', 'ORDER-001']);
});

test('the staged analysis reads the index, not the working tree', function () {
    // Stage a new dependency, then remove it again on disk only.
    $this->repo->replace('app/Services/InvoiceService.php', 'return [];', 'return (new PriceCalculator)->total([]) ? [] : [];');
    $this->repo->stage('app/Services/InvoiceService.php');
    $this->repo->replace('app/Services/InvoiceService.php', 'return (new PriceCalculator)->total([]) ? [] : [];', 'return [];');
    $this->repo->replace('app/Services/PriceCalculator.php', 'return 0;', 'return 3;');
    $this->repo->stage('app/Services/PriceCalculator.php');

    $staged = $this->repo->impact(ChangeSet::staged($this->repo->git));

    // In the staged snapshot InvoiceService depends on PriceCalculator, so INV-001 is affected.
    expect(FixtureRepo::journeyIds($staged))->toBe(['INV-001', 'ORDER-001']);
});

test('route file hunks are attributed to the routes they touch', function () {
    $this->repo->replace('routes/web.php', "->only(['index', 'show'])", "->only(['index', 'show'])->middleware('verified')");

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::journeyIds($report))->toBe(['INV-001'])
        ->and($report['files'][0]['detail'])->toBe('app.invoices.index, app.invoices.show');
});

test('a change to a route group attribute affects every route in the group', function () {
    $this->repo->replace('routes/web.php', "Route::middleware('auth')", "Route::middleware(['auth', 'verified'])");

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::journeyIds($report))->toBe(['INV-001', 'ORDER-001']);
});

test('a new route without a journey is reported', function () {
    $this->repo->replace('routes/web.php', "Route::get('/health'", "Route::get('/export', fn () => 'csv')->name('export');\n\nRoute::get('/health'");

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect($report['new_unmapped_routes'])->toBe(['export']);
});

test('a journey definition change marks that journey as directly affected', function () {
    $this->repo->replace('docs/user-journeys/journeys/invoices/INV-001-view-invoices.yaml', 'priority: medium', 'priority: high');

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::journeyIds($report))->toBe(['INV-001'])
        ->and(FixtureRepo::classification($report, 'docs/user-journeys/journeys/invoices/INV-001-view-invoices.yaml'))->toBe('journey_definition');
});

test('coverage regressions against the base are reported', function () {
    $this->repo->replace('docs/user-journeys/journeys/invoices/INV-001-view-invoices.yaml', "coverage: covered\n    tests: ['tests/Feature/InvoiceTest.php::invoices are listed']", 'coverage: uncovered');

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect($report['coverage_regressions'])->toBe(['INV-001.list: coverage regressed from covered to uncovered']);
});

test('an unparsable file is uncertain', function () {
    $this->repo->replace('app/Services/OrderService.php', 'class OrderService', 'class OrderService {{');

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::classification($report, 'app/Services/OrderService.php'))->toBe('unanalyzable')
        ->and($report['full_suite_recommended'])->toBeTrue();
});

test('non-journey code is classified without raising uncertainty', function () {
    $this->repo->replace('app/Console/Commands/Cleanup.php', 'class Cleanup', 'final class Cleanup');

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::classification($report, 'app/Console/Commands/Cleanup.php'))->toBe('non_journey')
        ->and($report['journeys'])->toBeEmpty()
        ->and($report['uncertain'])->toBeEmpty();
});

test('ignored files never affect journeys', function () {
    $this->repo->write('README.md', "# changed\n");

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect(FixtureRepo::classification($report, 'README.md'))->toBe('ignored')
        ->and($report['journeys'])->toBeEmpty()
        ->and($report['full_suite_recommended'])->toBeFalse();
});

test('a deleted test that a journey still references is uncertain', function () {
    $this->repo->delete('tests/Feature/InvoiceTest.php');

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    expect($report['uncertain'])->toContain('tests/Feature/InvoiceTest.php: removed/renamed test is still referenced by INV-001');
});

test('model relations are followed one hop only', function () {
    $this->repo->replace('app/Models/User.php', 'hasMany', 'hasMany /* changed */');

    $report = $this->repo->impact(ChangeSet::working($this->repo->git));

    // Order -> User is a relation edge: User is reached (one hop) from ORDER-001.
    expect(FixtureRepo::journeyIds($report))->toBe(['ORDER-001']);
});
