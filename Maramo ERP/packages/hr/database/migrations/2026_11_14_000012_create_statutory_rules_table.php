<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statutory_rule_set_id')->constrained('statutory_rule_sets')->cascadeOnDelete();
            $table->string('kind');
            $table->string('row_type')->default('param');
            $table->string('variant')->nullable();

            $table->string('param_key')->nullable();
            $table->string('param_value')->nullable();

            $table->integer('sequence')->default(0);
            $table->decimal('from_amount', 15, 2)->nullable();
            $table->decimal('to_amount', 15, 2)->nullable();
            $table->decimal('rate_percent', 8, 4)->nullable();
            $table->decimal('fixed_amount', 15, 2)->nullable();
            $table->unsignedTinyInteger('month')->nullable();

            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['statutory_rule_set_id', 'kind', 'row_type']);
            $table->index(['statutory_rule_set_id', 'kind', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_rules');
    }
};
