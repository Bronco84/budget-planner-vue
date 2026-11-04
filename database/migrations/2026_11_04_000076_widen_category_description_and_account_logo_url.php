<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen columns whose validation allows more than VARCHAR(255): the category
 * description (validated max:1000) and the account logo URL (validated
 * max:500). On MySQL in strict mode a longer value otherwise fails the write
 * with "Data too long". Neither column is indexed; both stay nullable with no
 * default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('logo_url', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Values written after the widening may not fit the old width; cut
        // them so the rollback cannot fail on strict MySQL.
        DB::table('categories')
            ->whereRaw('LENGTH(description) > 255')
            ->update(['description' => DB::raw('SUBSTR(description, 1, 255)')]);

        DB::table('accounts')
            ->whereRaw('LENGTH(logo_url) > 255')
            ->update(['logo_url' => DB::raw('SUBSTR(logo_url, 1, 255)')]);

        Schema::table('categories', function (Blueprint $table) {
            $table->string('description')->nullable()->change();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('logo_url')->nullable()->change();
        });
    }
};
