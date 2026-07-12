<?php

use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;

it('includes ongoing templates with no end date', function () {
    $budget = Budget::factory()->create();
    RecurringTransactionTemplate::factory()->for($budget)->create([
        'start_date' => now()->subMonth(),
        'end_date' => null,
    ]);

    expect($budget->recurringTransactionTemplates()->active()->count())->toBe(1);
});

it('excludes templates whose end_date has already passed', function () {
    $budget = Budget::factory()->create();
    RecurringTransactionTemplate::factory()->for($budget)->create([
        'start_date' => now()->subYear(),
        'end_date' => now()->subDay(),
    ]);

    expect($budget->recurringTransactionTemplates()->active()->count())->toBe(0);
});

it('excludes templates that have not started yet', function () {
    $budget = Budget::factory()->create();
    RecurringTransactionTemplate::factory()->for($budget)->create([
        'start_date' => now()->addDay(),
        'end_date' => null,
    ]);

    expect($budget->recurringTransactionTemplates()->active()->count())->toBe(0);
});

it('includes a template whose end_date is today', function () {
    $budget = Budget::factory()->create();
    RecurringTransactionTemplate::factory()->for($budget)->create([
        'start_date' => now()->subMonth(),
        'end_date' => now(),
    ]);

    expect($budget->recurringTransactionTemplates()->active()->count())->toBe(1);
});
