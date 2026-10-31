<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds an indexed sha256 hash of the raw device token so
     * DeviceTokenService::verifyDeviceToken can look a device up directly
     * instead of decrypting every trusted device. Existing tokens are
     * encrypted (reversibly) with the app key, so they are backfilled here;
     * rows that fail to decrypt keep a null hash and are handled by the
     * service's legacy fallback.
     */
    public function up(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->string('token_hash', 64)->nullable()->after('device_token');
            $table->index('token_hash');
        });

        DB::table('trusted_devices')
            ->select('id', 'device_token')
            ->whereNull('token_hash')
            ->orderBy('id')
            ->each(function ($row) {
                try {
                    $token = Crypt::decryptString($row->device_token);
                } catch (Throwable $e) {
                    return;
                }

                DB::table('trusted_devices')
                    ->where('id', $row->id)
                    ->update(['token_hash' => hash('sha256', $token)]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->dropIndex(['token_hash']);
            $table->dropColumn('token_hash');
        });
    }
};
