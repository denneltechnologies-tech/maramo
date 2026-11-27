<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('type')->default('allowance');
            $table->string('calculation')->default('fixed');
            $table->decimal('percentage', 8, 4)->nullable();
            $table->json('base_codes')->nullable();
            $table->string('statutory_kind')->nullable();
            $table->string('supersedes_statutory_kind', 40)->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->boolean('pf_wage')->default(false);
            $table->boolean('esi_wage')->default(true);
            $table->boolean('prorates_on_lop')->default(true);
            $table->boolean('emit_as_line')->default(true);
            $table->integer('display_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->boolean('status')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'code']);
            $table->index('school_id');
            $table->index(['branch_id', 'type']);
            $table->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_components');
    }
};
