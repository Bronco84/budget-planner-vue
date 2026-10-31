<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\User;
use App\Services\RecurringTransactionAnalysisService;
use App\Services\RecurringTransactionService;
use Illuminate\Support\Facades\DB;

/*
 * RECUR-001/002/006: a dynamic template's starting average (average_amount) is
 * stored in cents like every other template amount; forms take dollars.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
});

function averageAmountPayload(array $overrides = []): array
{
    return array_replace([
        'description' => 'Electric',
        'account_id' => test()->account->id,
        'category' => 'Utilities',
        'frequency' => 'monthly',
        'start_date' => '2026-01-01',
        'day_of_month' => 15,
        'is_dynamic_amount' => true,
        'average_amount' => -84.37,
    ], $overrides);
}

test('storing and updating a template converts the starting average from dollars to cents', function () {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), averageAmountPayload())
        ->assertSessionHasNoErrors();

    $template = RecurringTransactionTemplate::sole();
    expect($template->average_amount)->toBe(-8437);

    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.update', [$this->budget, $template]), averageAmountPayload(['average_amount' => 19.99]))
        ->assertSessionHasNoErrors();

    expect($template->fresh()->average_amount)->toBe(1999);

    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.update', [$this->budget, $template]), averageAmountPayload(['average_amount' => null]))
        ->assertSessionHasNoErrors();

    expect($template->fresh()->average_amount)->toBeNull();
});

test('a dynamic template without transactions projects its starting average in cents', function () {
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'is_dynamic_amount' => true,
        'amount_in_cents' => 0,
        'average_amount' => -8437,
    ]);

    expect(app(RecurringTransactionService::class)->projectedAmount($template))->toBe(-8437)
        ->and($template->calculateDynamicAmount())->toBe(-84.37);
});

test('templates confirmed from analysis store the starting average in cents', function () {
    app(RecurringTransactionAnalysisService::class)->createTemplatesFromPatterns($this->budget, $this->account, [[
        'description' => 'Electric',
        'category' => 'Utilities',
        'amount_type' => 'dynamic',
        'amount' => -84.37,
        'frequency' => 'monthly',
        'day_of_month' => 15,
        'start_date' => '2026-01-15',
        'transaction_ids' => [],
    ]]);

    expect(RecurringTransactionTemplate::sole()->average_amount)->toBe(-8437);
});

test('the conversion migration turns stored dollars into cents and back', function () {
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
    ]);
    $withoutAverage = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'average_amount' => null,
    ]);
    DB::table('recurring_transaction_templates')->where('id', $template->id)->update(['average_amount' => -85]);

    $migration = require database_path('migrations/2026_10_31_300100_convert_recurring_template_average_amount_to_cents.php');

    $migration->up();
    expect((int) DB::table('recurring_transaction_templates')->where('id', $template->id)->value('average_amount'))->toBe(-8500)
        ->and(DB::table('recurring_transaction_templates')->where('id', $withoutAverage->id)->value('average_amount'))->toBeNull();

    $migration->down();
    expect((int) DB::table('recurring_transaction_templates')->where('id', $template->id)->value('average_amount'))->toBe(-85);
});
