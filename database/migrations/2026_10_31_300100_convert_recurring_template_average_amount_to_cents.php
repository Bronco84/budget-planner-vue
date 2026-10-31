<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * recurring_transaction_templates.average_amount was stored in whole dollars in
 * an integer column while every other amount on the template is in cents.
 * Convert existing values to cents (x100) so readers treat it like the rest.
 *
 * Values whose cents would not fit the signed 32-bit integer column (over
 * about $21.4M) are left untouched rather than failing the deploy; no real
 * recurring average is anywhere near that.
 */
return new class extends Migration
{
    private const MAX_DOLLARS = 21474836;

    public function up(): void
    {
        DB::table('recurring_transaction_templates')
            ->whereNotNull('average_amount')
            ->whereBetween('average_amount', [-self::MAX_DOLLARS, self::MAX_DOLLARS])
            ->update(['average_amount' => DB::raw('average_amount * 100')]);
    }

    /**
     * Back to whole dollars (sub-dollar precision entered after the
     * conversion is rounded away, as the old column could not hold it).
     */
    public function down(): void
    {
        DB::table('recurring_transaction_templates')
            ->whereNotNull('average_amount')
            ->update(['average_amount' => DB::raw('ROUND(average_amount / 100.0)')]);
    }
};
