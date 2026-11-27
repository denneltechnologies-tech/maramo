<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('session_id')->nullable()->index();
            $table->string('name');
            $table->string('type')->default('marksheet'); // marksheet | report_card | admit_card | tabulation
            $table->string('layout')->default('generic'); // bundled layout or a view name
            $table->text('header_html')->nullable();
            $table->text('footer_html')->nullable();
            $table->string('page_size')->default('a4');
            $table->string('orientation')->default('portrait');
            $table->boolean('is_default')->default(false);
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_templates');
    }
};
