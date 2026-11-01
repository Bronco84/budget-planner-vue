<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\File;
use App\Models\FileAttachment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * FILE-001: attach, download and remove files on a transaction. S3 is always
 * faked; nothing here touches a real bucket.
 */

beforeEach(function () {
    Storage::fake('s3');
});

function fileTestTransactionFor(User $user): Transaction
{
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);

    return Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);
}

function receiptUpload(string $name, string $content = "%PDF-1.4\nreceipt body\n%%EOF"): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

test('uploading content another user already uploaded creates a separate file record for the uploader', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceTransaction = fileTestTransactionFor($alice);
    $bobTransaction = fileTestTransactionFor($bob);

    $this->actingAs($alice)
        ->post(route('transactions.files.upload', $aliceTransaction), ['file' => receiptUpload('alice-private-medical-bill.pdf')])
        ->assertCreated();

    $response = $this->actingAs($bob)
        ->post(route('transactions.files.upload', $bobTransaction), ['file' => receiptUpload('bob-receipt.pdf')])
        ->assertCreated()
        ->assertJsonPath('attachment.file.original_name', 'bob-receipt.pdf');

    expect($response->getContent())->not->toContain('alice-private-medical-bill');

    $bobAttachment = FileAttachment::findOrFail($response->json('attachment.id'));
    expect($bobAttachment->file->uploaded_by)->toBe($bob->id)
        ->and(File::count())->toBe(2);

    $this->actingAs($bob)
        ->getJson(route('transactions.files.index', $bobTransaction))
        ->assertOk()
        ->assertJsonPath('attachments.0.file.original_name', 'bob-receipt.pdf');
});

test('deleting one users copy keeps the stored object another user still uses', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $aliceId = $this->actingAs($alice)
        ->post(route('transactions.files.upload', fileTestTransactionFor($alice)), ['file' => receiptUpload('alice.pdf')])
        ->json('attachment.id');
    $bobId = $this->actingAs($bob)
        ->post(route('transactions.files.upload', fileTestTransactionFor($bob)), ['file' => receiptUpload('bob.pdf')])
        ->json('attachment.id');
    $hash = FileAttachment::findOrFail($bobId)->file->hash;

    $this->actingAs($alice)->deleteJson(route('files.delete', $aliceId))->assertOk();

    Storage::disk('s3')->assertExists($hash);
    expect(File::count())->toBe(1);
    $this->actingAs($bob)->get(route('files.download', $bobId))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="bob.pdf"; filename*=UTF-8\'\'bob.pdf');

    $this->actingAs($bob)->deleteJson(route('files.delete', $bobId))->assertOk();

    Storage::disk('s3')->assertMissing($hash);
    expect(File::count())->toBe(0);
});

test('the same user uploading identical content reuses their existing file record', function () {
    $user = User::factory()->create();
    $transaction = fileTestTransactionFor($user);
    $other = Transaction::factory()->create(['budget_id' => $transaction->budget_id, 'account_id' => $transaction->account_id]);

    $first = $this->actingAs($user)->post(route('transactions.files.upload', $transaction), ['file' => receiptUpload('receipt.pdf')]);
    $second = $this->actingAs($user)->post(route('transactions.files.upload', $other), ['file' => receiptUpload('copy.pdf')]);

    expect($second->json('attachment.file.id'))->toBe($first->json('attachment.file.id'))
        ->and(File::count())->toBe(1)
        ->and(FileAttachment::count())->toBe(2);
});

test('an S3 failure on upload returns a generic error and creates no records', function () {
    $user = User::factory()->create();
    $transaction = fileTestTransactionFor($user);

    $disk = Mockery::mock();
    $disk->shouldReceive('putFileAs')->andThrow(new RuntimeException('AccessDenied for bucket secret-prod-bucket'));
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

    $response = $this->actingAs($user)
        ->post(route('transactions.files.upload', $transaction), ['file' => receiptUpload('receipt.pdf')])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Failed to upload file. Please try again.']);

    expect($response->getContent())->not->toContain('secret-prod-bucket')
        ->and(File::count())->toBe(0)
        ->and(FileAttachment::count())->toBe(0);
});

test('a disallowed file type on a transaction upload still explains why it was rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('transactions.files.upload', fileTestTransactionFor($user)), ['file' => UploadedFile::fake()->create('tool.exe', 10)])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'File type not allowed']);
});

test('an oversized file on a transaction upload is rejected without storing anything', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('transactions.files.upload', fileTestTransactionFor($user)), ['file' => UploadedFile::fake()->create('scan.pdf', 10241, 'application/pdf')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(File::count())->toBe(0)
        ->and(FileAttachment::count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

test('a file whose extension does not match its content on a transaction upload is rejected without storing anything', function () {
    $user = User::factory()->create();
    // Plain text renamed to .pdf. A real (non-fake) UploadedFile so the MIME
    // type is sniffed from the content (text/plain), not guessed from the name.
    $path = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($path, "just some plain text pretending to be a receipt\n");
    $renamed = new UploadedFile($path, 'receipt.pdf', 'application/pdf', null, true);

    $this->actingAs($user)
        ->post(route('transactions.files.upload', fileTestTransactionFor($user)), ['file' => $renamed])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'File extension does not match file type. Expected: txt']);

    expect(File::count())->toBe(0)
        ->and(FileAttachment::count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

test('a failure deleting an attachment returns a generic error', function () {
    $user = User::factory()->create();
    $attachmentId = $this->actingAs($user)
        ->post(route('transactions.files.upload', fileTestTransactionFor($user)), ['file' => receiptUpload('receipt.pdf')])
        ->json('attachment.id');

    $this->mock(FileService::class, function ($mock) {
        $mock->shouldReceive('canUserAccessAttachment')->andReturnTrue();
        $mock->shouldReceive('detachFile')->andThrow(new RuntimeException('SQLSTATE[HY000] secret internals'));
    });

    $response = $this->actingAs($user)
        ->deleteJson(route('files.delete', $attachmentId))
        ->assertStatus(500)
        ->assertExactJson(['error' => 'Failed to delete file. Please try again.']);

    expect($response->getContent())->not->toContain('SQLSTATE');
});

test('downloading a receipt whose stored object is missing fails loudly instead of returning an empty file', function () {
    Log::spy();
    $user = User::factory()->create();
    $attachmentId = $this->actingAs($user)
        ->post(route('transactions.files.upload', fileTestTransactionFor($user)), ['file' => receiptUpload('receipt.pdf')])
        ->json('attachment.id');
    Storage::disk('s3')->delete(FileAttachment::findOrFail($attachmentId)->file->hash);

    $this->actingAs($user)
        ->get(route('files.download', $attachmentId))
        ->assertStatus(503);

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message, $context) => $message === 'Stored file could not be read for download'
            && $context['attachment_id'] === $attachmentId)
        ->once();
});

test('an unexpected failure while downloading a receipt is reported before the 500', function () {
    Exceptions::fake();
    $user = User::factory()->create();
    $attachmentId = $this->actingAs($user)
        ->post(route('transactions.files.upload', fileTestTransactionFor($user)), ['file' => receiptUpload('receipt.pdf')])
        ->json('attachment.id');

    $this->mock(FileService::class, function ($mock) {
        $mock->shouldReceive('canUserAccessAttachment')->andReturnTrue();
        $mock->shouldReceive('downloadResponse')->andThrow(new RuntimeException('S3 credentials rejected'));
    });

    $this->actingAs($user)
        ->get(route('files.download', $attachmentId))
        ->assertStatus(500);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'S3 credentials rejected');
});

test('the s3 disk reports storage failures it does not throw', function () {
    expect(config('filesystems.disks.s3.report'))->toBeTrue();
});
