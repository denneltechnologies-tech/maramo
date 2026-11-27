<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_refund_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('fee_refund_id')->constrained('fee_refunds')->cascadeOnDelete();
            $table->string('decision', 30); // requested|auto_approved|approved|rejected|voided|issued|paid_out
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable()->index();
            $table->timestamp('created_at')->nullable();
            $table->index('school_id');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_refund_decisions');
    }
};
