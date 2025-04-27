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
        Schema::create('recurring_transaction_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('recurring_transaction_template_id');
            $table->string('field'); // description, amount, category
            $table->string('operator'); // contains, equals, starts_with, ends_with, regex, greater_than, less_than
            $table->string('value');
            $table->boolean('is_case_sensitive')->default(false);
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->foreign('recurring_transaction_template_id', 'rec_trans_template_fk')
                ->references('id')
                ->on('recurring_transaction_templates')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_transaction_rules');
    }
};
