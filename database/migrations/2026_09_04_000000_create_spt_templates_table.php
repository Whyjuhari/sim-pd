<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spt_templates', function (Blueprint $table): void {
            $table->integer('id')->autoIncrement();
            $table->string('nama', 100);
            $table->string('deskripsi')->nullable();
            $table->string('file_path');
            $table->string('original_filename');
            $table->unsignedBigInteger('file_size');
            $table->char('file_sha256', 64);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('created_by');
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->foreign('created_by', 'spt_template_creator_foreign')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spt_templates');
    }
};
