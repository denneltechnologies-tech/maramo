<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_receipt_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unsignedInteger('next_seq')->default(1);
            $table->timestamps();
            $table->unique('branch_id');
            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_receipt_sequences');
    }
};
