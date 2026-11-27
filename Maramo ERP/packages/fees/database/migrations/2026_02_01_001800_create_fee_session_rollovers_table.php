<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_session_rollovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('from_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('to_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->decimal('percent', 8, 2)->default(0);
            $table->unsignedInteger('rounding')->default(0);
            $table->string('rounding_mode', 16)->default('nearest');
            $table->integer('shift_months')->default(0);
            $table->unsignedInteger('groups_created')->default(0);
            $table->unsignedInteger('masters_created')->default(0);
            $table->unsignedInteger('masters_skipped')->default(0);
            $table->unsignedInteger('masters_blocked')->default(0);
            $table->unsignedInteger('students_carried')->default(0);
            $table->decimal('amount_carried', 15, 2)->default(0);
            $table->unsignedBigInteger('committed_by')->nullable()->index();
            $table->timestamps();
            $table->index(['from_session_id', 'to_session_id'], 'fee_rollover_pair_index');
            $table->index('school_id');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_session_rollovers');
    }
};
