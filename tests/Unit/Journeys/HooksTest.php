<?php

use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Unit\Journeys\FixtureRepo;
use Tooling\Journeys\Hooks\Hooks;
use Tooling\Journeys\Impact\ChangeSet;

beforeEach(function () {
    $this->repo = FixtureRepo::create();
    $this->out = new BufferedOutput;
    $this->hooks = new Hooks($this->repo->git, $this->out);
    putenv('FIXTURE_TESTS_FAIL=0');
    putenv('JOURNEYS_CACHE_DIR='.$this->repo->root.'/.cache');
});

afterEach(function () {
    putenv('FIXTURE_TESTS_FAIL');
    putenv('JOURNEYS_CACHE_DIR');
});

test('pre-commit passes a valid staged change and reports the affected journey', function () {
    $this->repo->replace('app/Services/PriceCalculator.php', 'return 0;', 'return 5;');
    $this->repo->stage('app/Services/PriceCalculator.php');

    expect($this->hooks->preCommit())->toBe(0)
        ->and($this->out->fetch())->toContain('ORDER-001')->toContain('Running:');
});

test('pre-commit blocks an invalid staged journey definition', function () {
    $file = 'docs/user-journeys/journeys/orders/ORDER-001-place-order.yaml';
    $this->repo->replace($file, 'priority: critical', 'priority: urgent');
    $this->repo->stage($file);

    expect($this->hooks->preCommit())->toBe(1)
        ->and($this->out->fetch())->toContain('[schema]')->toContain('Commit blocked');
});

test('pre-commit ignores invalid edits that are not staged', function () {
    $this->repo->replace('app/Services/PriceCalculator.php', 'return 0;', 'return 5;');
    $this->repo->stage('app/Services/PriceCalculator.php');
    $this->repo->replace('docs/user-journeys/journeys/orders/ORDER-001-place-order.yaml', 'priority: critical', 'priority: urgent');

    expect($this->hooks->preCommit())->toBe(0);
});

test('pre-commit blocks when affected tests fail', function () {
    putenv('FIXTURE_TESTS_FAIL=1');
    $this->repo->replace('app/Services/PriceCalculator.php', 'return 0;', 'return 5;');
    $this->repo->stage('app/Services/PriceCalculator.php');

    expect($this->hooks->preCommit())->toBe(1)
        ->and($this->out->fetch())->toContain('affected tests failed');
});

test('pre-commit blocks a stale generated index', function () {
    $file = 'docs/user-journeys/journeys/orders/ORDER-001-place-order.yaml';
    $this->repo->replace($file, 'Customer places an order', 'Customer places orders');
    $this->repo->stage($file);

    expect($this->hooks->preCommit())->toBe(1)
        ->and($this->out->fetch())->toContain('index_out_of_date');
});

test('pre-commit with nothing staged does nothing', function () {
    expect($this->hooks->preCommit())->toBe(0)
        ->and($this->out->fetch())->toBe('');
});

test('pre-push blocks a new route that no journey claims', function () {
    $base = trim($this->repo->git('rev-parse', 'HEAD'));
    $this->repo->replace('routes/web.php', "Route::get('/health'", "Route::get('/export', fn () => 'csv')->name('export');\n\nRoute::get('/health'");
    $this->repo->commit('add export');
    $head = trim($this->repo->git('rev-parse', 'HEAD'));

    expect($this->hooks->prePush("refs/heads/main {$head} refs/heads/main {$base}\n"))->toBe(1)
        ->and($this->out->fetch())->toContain('export')->toContain('Push blocked');
});

test('pre-push passes a clean outgoing range', function () {
    $base = trim($this->repo->git('rev-parse', 'HEAD'));
    $this->repo->replace('app/Services/InvoiceService.php', 'return [];', 'return [3];');
    $this->repo->commit('tweak');
    $head = trim($this->repo->git('rev-parse', 'HEAD'));

    expect($this->hooks->prePush("refs/heads/main {$head} refs/heads/main {$base}\n"))->toBe(0)
        ->and($this->out->fetch())->toContain('INV-001');
});

test('pre-push skips branch deletions', function () {
    $zero = str_repeat('0', 40);

    expect($this->hooks->prePush("(delete) {$zero} refs/heads/old ".trim($this->repo->git('rev-parse', 'HEAD'))."\n"))->toBe(0);
});

test('fixture git commands ignore an inherited GIT_INDEX_FILE (tests run inside hooks)', function () {
    // git exports GIT_INDEX_FILE to hooks; a fixture that honoured it would
    // write into the outer repository's index.
    $outer = sys_get_temp_dir().'/journeys-outer-index-'.bin2hex(random_bytes(4));
    putenv('GIT_INDEX_FILE='.$outer);

    try {
        $repo = FixtureRepo::create();
        $repo->replace('app/Services/PriceCalculator.php', 'return 0;', 'return 9;');
        $repo->stage('app/Services/PriceCalculator.php');

        expect(file_exists($outer))->toBeFalse()
            ->and(FixtureRepo::journeyIds($repo->impact(ChangeSet::staged($repo->git))))->toBe(['ORDER-001']);
    } finally {
        putenv('GIT_INDEX_FILE');
        is_file($outer) && unlink($outer);
    }
});
