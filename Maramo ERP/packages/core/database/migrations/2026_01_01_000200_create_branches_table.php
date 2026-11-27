<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('logo_path')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('postcode')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('alt_phone')->nullable();
            $table->string('website')->nullable();
            $table->string('principal_name')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('currency_symbol', 8)->nullable();
            $table->string('timezone')->nullable();
            $table->string('language', 8)->nullable();
            $table->string('date_format')->nullable();
            $table->json('weekend_days')->nullable(); // 0=Sun .. 6=Sat
            $table->string('reg_no_prefix')->nullable();
            $table->unsignedInteger('reg_no_start')->default(1);
            $table->unsignedTinyInteger('reg_no_digits')->default(4);
            $table->unsignedTinyInteger('session_start_month')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['school_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
