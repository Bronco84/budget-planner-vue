<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->integer('order')->default(0)->after('color');
        });

        // Set sequential order values for existing categories grouped by budget
        $budgets = DB::table('categories')->select('budget_id')->distinct()->get();

        foreach ($budgets as $budget) {
            $categories = DB::table('categories')
                ->where('budget_id', $budget->budget_id)
                ->orderBy('id')
                ->get();

            $order = 1;
            foreach ($categories as $category) {
                DB::table('categories')
                    ->where('id', $category->id)
                    ->update(['order' => $order++]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('order');
        });
    }
};
