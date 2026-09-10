<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spt_srikandi_workflows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('spt_group_id')->unique();
            $table->string('status', 30)->index();
            $table->string('external_number', 50)->nullable()->unique();

            $table->string('draft_pdf_path', 500)->nullable();
            $table->char('draft_pdf_sha256', 64)->nullable();

            $table->string('official_pdf_path', 500)->nullable();
            $table->string('official_original_name')->nullable();
            $table->string('official_mime_type', 100)->nullable();
            $table->unsignedBigInteger('official_size_bytes')->nullable();
            $table->char('official_pdf_sha256', 64)->nullable()->unique();

            $table->integer('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->integer('uploaded_by')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->integer('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->foreign('submitted_by', 'spt_srikandi_submitted_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('uploaded_by', 'spt_srikandi_uploaded_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('published_by', 'spt_srikandi_published_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spt_srikandi_workflows');
    }
};
