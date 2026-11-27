<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('student_id')->index();
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('payment_date')->nullable();
            $table->string('mode', 20)->default('bank_transfer');
            $table->string('reference_no')->nullable();
            $table->string('attachment_path')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->unsignedBigInteger('submitted_by')->nullable()->index();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->foreignId('fee_payment_id')->nullable()->constrained('fee_payments')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('school_id');
            $table->index('branch_id');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_payments');
    }
};
