<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending invitations to join a shared budget. Only a SHA-256 hash of the
 * invitation token is stored; the plaintext token exists only in the emailed link.
 * New table, so it is safe to run against existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20)->default('viewer');
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['budget_id', 'email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_invitations');
    }
};
