<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\File;
use App\Models\FileAttachment;
use App\Models\PlaidTransaction;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;

/*
 * plaid:cleanup-duplicates deletes the older copy of a duplicated Plaid transaction.
 * Nothing the user added to the deleted copy may be lost: its receipts and edits move
 * to the kept (newer) copy, and where both copies have a value the kept copy's wins.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->create(['budget_id' => $this->budget->id]);

    // An imported copy and its raw Plaid row, created at the given time.
    $this->copy = function (string $plaidId, string $createdAt, array $attributes = []) {
        PlaidTransaction::create([
            'plaid_transaction_id' => $plaidId, 'account_id' => $this->account->id, 'plaid_account_id' => 'plaid-checking',
            'pending' => true, 'amount' => 20.00, 'date' => '2026-03-12', 'name' => 'HARDWARE 4821', 'category' => 'Shops',
        ]);

        $this->travelTo(Carbon\Carbon::parse($createdAt));
        $transaction = Transaction::create([
            'budget_id' => $this->budget->id, 'account_id' => $this->account->id, 'description' => 'HARDWARE 4821',
            'category' => 'Shops', 'amount_in_cents' => -2000, 'date' => '2026-03-12',
            'plaid_transaction_id' => $plaidId, 'is_plaid_imported' => true, ...$attributes,
        ]);
        $this->travelBack();

        return $transaction;
    };

    $this->attach = function (string $hash, Transaction $to) {
        $file = File::firstOrCreate(['hash' => str_repeat($hash, 64)], [
            'original_name' => "{$hash}.pdf", 'mime_type' => 'application/pdf',
            'size_bytes' => 12, 'extension' => 'pdf', 'uploaded_by' => $this->user->id,
        ]);

        return FileAttachment::create([
            'file_id' => $file->id, 'attachable_type' => Transaction::class,
            'attachable_id' => $to->id, 'attached_by' => $this->user->id,
        ]);
    };
});

test('cleaning up a duplicate moves its receipts, notes, category, description and recurring link to the kept copy', function () {
    $template = RecurringTransactionTemplate::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);
    $home = Category::create(['budget_id' => $this->budget->id, 'name' => 'Home', 'amount' => 0]);

    $deleted = ($this->copy)('tx-old', '2026-03-12 09:00', [
        'description' => 'HARDWARE 4821 drill bits', 'category_id' => $home->id, 'notes' => 'for the shed',
        'recurring_transaction_template_id' => $template->id, 'link_source' => Transaction::LINK_SOURCE_MANUAL,
    ]);
    $kept = ($this->copy)('tx-new', '2026-03-13 09:00');

    $receipt = ($this->attach)('a', $deleted);
    ($this->attach)('b', $deleted);
    ($this->attach)('b', $kept);

    $this->artisan('plaid:cleanup-duplicates')->assertSuccessful();

    expect($deleted->fresh())->toBeNull()
        ->and($kept->fresh())
        ->description->toBe('HARDWARE 4821 drill bits')
        ->category_id->toBe($home->id)
        ->notes->toBe('for the shed')
        ->recurring_transaction_template_id->toBe($template->id)
        ->link_source->toBe(Transaction::LINK_SOURCE_MANUAL)
        ->and($receipt->fresh()->attachable_id)->toBe($kept->id)
        ->and($kept->fileAttachments()->count())->toBe(2)
        ->and(FileAttachment::where('attachable_type', Transaction::class)->where('attachable_id', $deleted->id)->exists())->toBeFalse();
});

test('cleaning up a duplicate keeps the kept copy\'s own values where both copies have one', function () {
    $keptTemplate = RecurringTransactionTemplate::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);
    $otherTemplate = RecurringTransactionTemplate::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);

    $deleted = ($this->copy)('tx-old', '2026-03-12 09:00', [
        'description' => 'HARDWARE 4821 old note', 'category' => 'Garden', 'notes' => 'old',
        'recurring_transaction_template_id' => $otherTemplate->id, 'link_source' => Transaction::LINK_SOURCE_MANUAL,
    ]);
    $kept = ($this->copy)('tx-new', '2026-03-13 09:00', [
        'description' => 'HARDWARE 4821 new note', 'category' => 'Tools', 'notes' => 'new',
        'recurring_transaction_template_id' => $keptTemplate->id, 'link_source' => Transaction::LINK_SOURCE_RULE,
    ]);

    $this->artisan('plaid:cleanup-duplicates')->assertSuccessful();

    expect($deleted->fresh())->toBeNull()
        ->and($kept->fresh())
        ->description->toBe('HARDWARE 4821 new note')
        ->category->toBe('Tools')
        ->notes->toBe('new')
        ->recurring_transaction_template_id->toBe($keptTemplate->id)
        ->link_source->toBe(Transaction::LINK_SOURCE_RULE);
});

test('a dry run of the duplicate cleanup changes nothing', function () {
    $deleted = ($this->copy)('tx-old', '2026-03-12 09:00', ['notes' => 'for the shed']);
    $kept = ($this->copy)('tx-new', '2026-03-13 09:00');
    $receipt = ($this->attach)('a', $deleted);

    $this->artisan('plaid:cleanup-duplicates', ['--dry-run' => true])->assertSuccessful();

    expect($deleted->fresh())->not->toBeNull()
        ->and($kept->fresh()->notes)->toBeNull()
        ->and($receipt->fresh()->attachable_id)->toBe($deleted->id);
});
