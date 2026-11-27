<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * timetable_rooms — shared rooms the generator books for subjects that need a
 * room type (e.g. two rooms of type "lab"). Tenant + branch scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('type', 50);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index('school_id');
            $table->unique(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_rooms');
    }
};
