<?php

use Tests\Unit\Journeys\FixtureRepo;
use Tooling\Journeys\Git\Snapshot;
use Tooling\Journeys\Validation\Issue;
use Tooling\Journeys\Validation\Validator;

function journeyIssues(FixtureRepo $repo, string $kind = Snapshot::WORKTREE, bool $strict = false): array
{
    return array_map(fn (Issue $i) => [$i->severity, $i->code, $i->journey], (new Validator($repo->workspace($kind)))->validate($strict));
}

function issueCodes(array $issues, string $severity): array
{
    return array_values(array_unique(array_map(fn ($i) => $i[1], array_filter($issues, fn ($i) => $i[0] === $severity))));
}

beforeEach(function () {
    $this->repo = FixtureRepo::create();
    $this->order = 'docs/user-journeys/journeys/orders/ORDER-001-place-order.yaml';
});

test('the fixture catalog is valid', function () {
    expect(issueCodes(journeyIssues($this->repo), Issue::ERROR))->toBe([]);
});

test('a reference to a test name that does not exist is an error', function () {
    $this->repo->replace($this->order, '::user can list orders', '::user can list all orders');
    $this->repo->regenerateIndex();

    expect(journeyIssues($this->repo))->toContain([Issue::ERROR, 'broken_test_ref', 'ORDER-001']);
});

test('a reference to a missing test file is an error', function () {
    $this->repo->replace($this->order, 'tests/Feature/OrderTest.php::user can list orders', 'tests/Feature/GoneTest.php');
    $this->repo->regenerateIndex();

    expect(journeyIssues($this->repo))->toContain([Issue::ERROR, 'broken_test_ref', 'ORDER-001']);
});

test('a referenced test without assertions is an error', function () {
    $this->repo->replace($this->order, '::user can list orders', '::smoke without assertions');
    $this->repo->regenerateIndex();

    expect(journeyIssues($this->repo))->toContain([Issue::ERROR, 'test_without_assertions', 'ORDER-001']);
});

test('a test that never touches the journey is flagged as a stale mapping', function () {
    $this->repo->replace($this->order, 'tests/Feature/OrderTest.php::user can list orders', 'tests/Feature/InvoiceTest.php::invoices are listed');
    $this->repo->regenerateIndex();

    expect(journeyIssues($this->repo))->toContain([Issue::WARNING, 'stale_test_mapping', 'ORDER-001']);
});

test('an unknown route is an error', function () {
    $this->repo->replace($this->order, 'app.orders.store]', 'app.orders.destroy]');
    $this->repo->regenerateIndex();

    expect(journeyIssues($this->repo))->toContain([Issue::ERROR, 'unknown_route', 'ORDER-001']);
});

test('schema violations are errors', function () {
    $this->repo->replace($this->order, 'priority: critical', 'priority: urgent');

    expect(issueCodes(journeyIssues($this->repo), Issue::ERROR))->toContain('schema');
});

test('covered scenarios must cite tests', function () {
    $this->repo->replace($this->order, "coverage: covered\n    tests: ['tests/Feature/OrderTest.php::user can list orders']", 'coverage: covered');

    expect(issueCodes(journeyIssues($this->repo), Issue::ERROR))->toContain('schema');
});

test('a stale generated index is an error', function () {
    $this->repo->replace($this->order, 'Customer places an order', 'Customer places orders');

    expect(issueCodes(journeyIssues($this->repo), Issue::ERROR))->toContain('index_out_of_date');
});

test('dropping recorded coverage is an error until the baseline is updated', function () {
    $this->repo->replace('docs/user-journeys/journeys/invoices/INV-001-view-invoices.yaml', "coverage: covered\n    tests: ['tests/Feature/InvoiceTest.php::invoices are listed']", 'coverage: uncovered');
    $this->repo->write('docs/user-journeys/INDEX.md', ''); // keep focus on the baseline
    $issues = journeyIssues($this->repo);

    expect(issueCodes($issues, Issue::ERROR))->toContain('coverage_regression');
});

test('routes outside any journey are warnings, and errors in strict mode', function () {
    $this->repo->replace('routes/web.php', "Route::get('/health'", "Route::get('/export', fn () => 'csv')->name('export');\n\nRoute::get('/health'");

    expect(journeyIssues($this->repo))->toContain([Issue::WARNING, 'unmapped_route', null])
        ->and(journeyIssues($this->repo, strict: true))->toContain([Issue::ERROR, 'unmapped_route', null]);
});

test('unresolved application classes are reported, not dropped', function () {
    $this->repo->replace('app/Services/OrderService.php', 'return Order::all();', 'return \App\Services\Missing::all();');

    expect(journeyIssues($this->repo))->toContain([Issue::WARNING, 'unresolved_dependency', null]);
});

test('a missing Inertia page is reported as unresolved', function () {
    $this->repo->delete('resources/js/Pages/Invoices/Show.vue');

    expect(journeyIssues($this->repo))->toContain([Issue::WARNING, 'unresolved_dependency', null]);
});

test('validation of the staged snapshot ignores unstaged edits', function () {
    $this->repo->replace($this->order, 'priority: critical', 'priority: urgent'); // unstaged

    expect(issueCodes(journeyIssues($this->repo, Snapshot::INDEX), Issue::ERROR))->toBe([])
        ->and(issueCodes(journeyIssues($this->repo, Snapshot::WORKTREE), Issue::ERROR))->toContain('schema');
});
