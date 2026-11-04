<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\File;
use App\Models\FileAttachment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePlaidApi;

/*
 * PR-054. A failed S3 delete (the s3 disk has throw=false, so delete() returns
 * false) must never strand an object: the File row is kept, detached from a
 * deleted account, and files:cleanup-orphans retries it daily.
 */

function orphanCleanupStoredFile(?User $user, string $hash): File
{
    Storage::disk('s3')->put($hash, 'content-'.$hash);

    return File::create([
        'hash' => $hash,
        'original_name' => $hash.'.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 12,
        'extension' => 'pdf',
        'uploaded_by' => $user?->id,
    ]);
}

/**
 * Swap the faked s3 disk for one whose deletes fail the way a throw=false S3
 * disk does during an outage: they return false and leave the object.
 */
function orphanCleanupFailS3Deletes(): void
{
    $disk = Mockery::mock(Storage::disk('s3'));
    $disk->shouldReceive('delete')->andReturn(false);
    Storage::set('s3', $disk);
}

test('a file whose S3 delete fails during account deletion is detached from the user and kept for cleanup', function () {
    $fake = Storage::fake('s3');
    $plaid = FakePlaidApi::install();
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);
    $receipt = orphanCleanupStoredFile($user, 'hash-receipt');
    FileAttachment::create([
        'file_id' => $receipt->id,
        'attachable_type' => Transaction::class,
        'attachable_id' => $transaction->id,
        'attached_by' => $user->id,
    ]);
    $unattached = orphanCleanupStoredFile($user, 'hash-unattached');

    orphanCleanupFailS3Deletes();
    app(AccountDeletionService::class)->delete($user);

    expect(User::find($user->id))->toBeNull()
        ->and(Budget::find($budget->id))->toBeNull()
        ->and(FileAttachment::count())->toBe(0)
        ->and($receipt->fresh()?->uploaded_by)->toBeNull()
        ->and($unattached->fresh()?->uploaded_by)->toBeNull();
    $fake->assertExists(['hash-receipt', 'hash-unattached']);

    // S3 is back: the scheduled cleanup reclaims both objects and records.
    Storage::set('s3', $fake);
    $this->artisan('files:cleanup-orphans', ['--hours' => 0])->assertSuccessful();

    expect(File::count())->toBe(0);
    $fake->assertMissing(['hash-receipt', 'hash-unattached']);
});

test('files:cleanup-orphans removes old unattached files and keeps attached, recent and still failing ones', function () {
    $fake = Storage::fake('s3');
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $attached = orphanCleanupStoredFile($user, 'hash-attached');
    FileAttachment::create([
        'file_id' => $attached->id,
        'attachable_type' => Budget::class,
        'attachable_id' => $budget->id,
        'attached_by' => $user->id,
    ]);
    $orphan = orphanCleanupStoredFile(null, 'hash-orphan');
    // Same content as the attached file under another record: the shared object stays.
    $sharedOrphan = orphanCleanupStoredFile(null, 'hash-attached');

    $this->travel(2)->days();
    $recent = orphanCleanupStoredFile($user, 'hash-recent');

    $this->artisan('files:cleanup-orphans')->assertSuccessful();

    expect(File::find($orphan->id))->toBeNull()
        ->and(File::find($sharedOrphan->id))->toBeNull()
        ->and(File::find($attached->id))->not->toBeNull()
        ->and(File::find($recent->id))->not->toBeNull();
    $fake->assertMissing('hash-orphan');
    $fake->assertExists(['hash-attached', 'hash-recent']);

    // While S3 deletes keep failing, the record stays for the next run.
    $stuck = orphanCleanupStoredFile(null, 'hash-stuck');
    $this->travel(2)->days();
    orphanCleanupFailS3Deletes();

    $this->artisan('files:cleanup-orphans')->assertSuccessful();

    expect(File::find($stuck->id))->not->toBeNull();
    $fake->assertExists('hash-stuck');
});

test('files:cleanup-orphans is scheduled daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command, 'files:cleanup-orphans'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *');
});
