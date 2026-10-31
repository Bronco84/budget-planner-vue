<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * REPORT-001: net worth series pinned for a small fixture, plus a guard that it
 * is computed with a constant number of queries rather than one SUM per
 * account per data point.
 *
 * Liability balances are positive debt rolled back with the opposite sign to
 * assets (a purchase after the date means the debt was lower then).
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(12, 0));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);

    $this->checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 500000]);
    $this->savings = Account::factory()->savings()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 1000000]);
    $this->card = Account::factory()->creditCard()->create(['budget_id' => $this->budget->id, 'current_balance_cents' => 120000]);

    $rows = [
        [$this->checking, '2026-01-01', 250000],
        [$this->checking, '2026-01-04', -4500],   // lands exactly on a data point
        [$this->checking, '2026-02-10', -80000],
        [$this->checking, '2026-03-15', 250000],
        [$this->checking, '2026-03-31', -12000],  // on the end date
        [$this->checking, '2026-05-01', -30000],  // after the range
        [$this->savings, '2026-02-01', 50000],
        [$this->savings, '2026-04-01', 50000],
        [$this->card, '2026-01-20', -15000],
        [$this->card, '2026-02-20', 40000],
        [$this->card, '2026-03-30', -7000],
        [$this->card, '2025-12-01', -1000],       // before the range
    ];

    foreach ($rows as [$account, $date, $amount]) {
        Transaction::create([
            'budget_id' => $this->budget->id,
            'account_id' => $account->id,
            'description' => 'Fixture',
            'category' => 'Misc',
            'amount_in_cents' => $amount,
            'date' => $date,
        ]);
    }
});

function netWorthReport($test, array $query = []): array
{
    $response = $test->actingAs($test->user)->get(route('reports.index', array_merge([
        $test->budget,
        'date_range' => 'custom',
        'start_date' => '2026-01-01',
        'end_date' => '2026-03-31',
    ], $query)));

    $response->assertOk();

    return $response->viewData('page')['props']['netWorth'];
}

function compactNetWorthSeries(array $netWorth): array
{
    return array_map(
        fn ($p) => "{$p['date']} {$p['assets']} {$p['liabilities']} {$p['netWorth']}",
        $netWorth['dataPoints'],
    );
}

function netWorthBalanceQueries(array $log): array
{
    return array_values(array_filter(
        $log,
        fn ($q) => str_contains($q['query'], '"transactions"') && str_contains(strtolower($q['query']), 'sum('),
    ));
}

test('net worth series for all accounts matches the pinned values', function () {
    $netWorth = netWorthReport($this);

    expect(compactNetWorthSeries($netWorth))->toBe([
        '2026-01-01 1276500 138000 1138500',
        '2026-01-04 1272000 138000 1134000',
        '2026-01-07 1272000 138000 1134000',
        '2026-01-10 1272000 138000 1134000',
        '2026-01-13 1272000 138000 1134000',
        '2026-01-16 1272000 138000 1134000',
        '2026-01-19 1272000 138000 1134000',
        '2026-01-22 1272000 153000 1119000',
        '2026-01-25 1272000 153000 1119000',
        '2026-01-28 1272000 153000 1119000',
        '2026-01-31 1272000 153000 1119000',
        '2026-02-03 1322000 153000 1169000',
        '2026-02-06 1322000 153000 1169000',
        '2026-02-09 1322000 153000 1169000',
        '2026-02-12 1242000 153000 1089000',
        '2026-02-15 1242000 153000 1089000',
        '2026-02-18 1242000 153000 1089000',
        '2026-02-21 1242000 113000 1129000',
        '2026-02-24 1242000 113000 1129000',
        '2026-02-27 1242000 113000 1129000',
        '2026-03-02 1242000 113000 1129000',
        '2026-03-05 1242000 113000 1129000',
        '2026-03-08 1242000 113000 1129000',
        '2026-03-11 1242000 113000 1129000',
        '2026-03-14 1242000 113000 1129000',
        '2026-03-17 1492000 113000 1379000',
        '2026-03-20 1492000 113000 1379000',
        '2026-03-23 1492000 113000 1379000',
        '2026-03-26 1492000 113000 1379000',
        '2026-03-29 1492000 113000 1379000',
        '2026-03-31 1480000 120000 1360000',
    ])->and($netWorth['summary'])->toMatchArray([
        'currentAssets' => 1480000,
        'currentLiabilities' => 120000,
        'currentNetWorth' => 1360000,
        'startingNetWorth' => 1138500,
        'change' => 221500,
    ])->and($netWorth['summary']['changePercent'])->toEqualWithDelta(19.455423803249890, 1e-9);
});

test('net worth filtered to one account matches the pinned values', function () {
    $netWorth = netWorthReport($this, ['account_id' => $this->card->id]);

    expect($netWorth['dataPoints'])->toHaveCount(31)
        ->and($netWorth['dataPoints'][0])->toBe(['date' => '2026-01-01', 'assets' => 0, 'liabilities' => 138000, 'netWorth' => -138000])
        ->and($netWorth['dataPoints'][7])->toBe(['date' => '2026-01-22', 'assets' => 0, 'liabilities' => 153000, 'netWorth' => -153000])
        ->and($netWorth['dataPoints'][17])->toBe(['date' => '2026-02-21', 'assets' => 0, 'liabilities' => 113000, 'netWorth' => -113000])
        ->and($netWorth['summary'])->toMatchArray([
            'currentAssets' => 0,
            'currentLiabilities' => 120000,
            'currentNetWorth' => -120000,
            'startingNetWorth' => -138000,
            'change' => 18000,
        ]);
});

test('net worth balances are computed with one grouped query instead of one sum per account per data point', function () {
    DB::enableQueryLog();
    netWorthReport($this);
    $queries = netWorthBalanceQueries(DB::getQueryLog());
    DB::disableQueryLog();

    // Previously 31 data points x 3 accounts = 93 SUM queries.
    expect($queries)->toHaveCount(1);
});
