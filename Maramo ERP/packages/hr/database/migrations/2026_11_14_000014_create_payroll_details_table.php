<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained('payrolls')->cascadeOnDelete();
            $table->foreignId('salary_component_id')->nullable()->constrained('salary_components')->nullOnDelete();
            $table->string('type');
            $table->string('name');
            $table->string('source')->default('legacy');
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('computed_amount', 15, 2)->nullable();
            $table->boolean('is_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->unsignedBigInteger('overridden_by')->nullable();
            $table->timestamp('overridden_at')->nullable();
            $table->integer('sequence')->default(0);
            $table->timestamps();

            $table->index('payroll_id');
            $table->index('type');
            $table->index(['payroll_id', 'sequence']);
            $table->index('is_override');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_details');
    }
};
