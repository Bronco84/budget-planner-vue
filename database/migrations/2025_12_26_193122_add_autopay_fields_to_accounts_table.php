<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Autopay configuration fields
            $table->boolean('autopay_enabled')->default(false)->after('id');
            $table->unsignedBigInteger('autopay_source_account_id')->nullable()->after('autopay_enabled');
            $table->bigInteger('autopay_amount_override_cents')->nullable()->after('autopay_source_account_id');

            // Foreign key constraint
            $table->foreign('autopay_source_account_id')
                ->references('id')
                ->on('accounts')
                ->onDelete('set null');

            // Index for query performance
            $table->index(['autopay_enabled', 'autopay_source_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Drop index
            $table->dropIndex(['autopay_enabled', 'autopay_source_account_id']);

            // Drop foreign key
            $table->dropForeign(['autopay_source_account_id']);

            // Drop columns
            $table->dropColumn([
                'autopay_enabled',
                'autopay_source_account_id',
                'autopay_amount_override_cents',
            ]);
        });
    }
};
