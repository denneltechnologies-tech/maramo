<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_rule_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('financial_year', 9);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('country_code', 2)->default('IN');
            $table->string('state_code', 10)->nullable();
            $table->boolean('status')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('school_id');
            $table->index(['school_id', 'branch_id', 'effective_from']);
            $table->index('financial_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_rule_sets');
    }
};
