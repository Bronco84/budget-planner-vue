<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legacy laragear/webauthn credential table, written out as the plain
 * schema laragear's WebAuthnCredential::migration() produced, so it still runs
 * after that (abandoned) package was replaced by laravel/passkeys. Existing
 * rows were copied into `passkeys` by 2026_11_04_000049; the table is kept so
 * that copy can be rolled back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->string('id', 510)->primary();
            $table->morphs('authenticatable', 'webauthn_user_index');
            $table->uuid('user_id');
            $table->string('alias')->nullable();
            $table->unsignedBigInteger('counter')->nullable();
            $table->string('rp_id');
            $table->string('origin');
            $table->json('transports')->nullable();
            $table->uuid('aaguid')->nullable();
            $table->text('public_key');
            $table->string('attestation_format')->default('none');
            $table->json('certificates')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webauthn_credentials');
    }
};
