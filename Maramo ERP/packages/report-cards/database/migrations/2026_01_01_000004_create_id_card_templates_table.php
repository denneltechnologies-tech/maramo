<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_card_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('name');
            $table->string('type')->default('student'); // student | staff
            $table->json('fields')->nullable();
            $table->boolean('show_photo')->default(true);
            $table->boolean('show_qr')->default(true);
            $table->boolean('show_logo')->default(true);
            $table->string('header_color')->default('#1e293b');
            $table->string('text_color')->default('#1e293b');
            $table->string('orientation')->default('landscape');
            $table->string('card_size')->default('cr80');
            $table->string('logo_path')->nullable();
            $table->string('background_path')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_card_templates');
    }
};
