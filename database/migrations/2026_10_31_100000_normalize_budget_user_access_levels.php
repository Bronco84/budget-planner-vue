<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Budget sharing roles are viewer / editor / owner. The pivot was created with a
 * legacy 'admin' level in mind; map any such rows to 'owner'. Pure data update:
 * safe on existing data (the table is expected to be empty) and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('budget_user')->where('access_level', 'admin')->update(['access_level' => 'owner']);
    }

    public function down(): void
    {
        DB::table('budget_user')->where('access_level', 'owner')->update(['access_level' => 'admin']);
    }
};
