<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spt_srikandi_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedInteger('version_number');
            $table->string('docx_path', 500);
            $table->string('docx_original_name');
            $table->string('docx_mime_type', 100)
                ->default('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            $table->unsignedBigInteger('docx_size_bytes');
            $table->char('docx_sha256', 64);
            $table->integer('prepared_by')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->integer('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('revision_reason')->nullable();
            $table->integer('revision_requested_by')->nullable();
            $table->timestamp('revision_requested_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_id', 'version_number'], 'spt_srikandi_version_number_unique');
            $table->index('docx_sha256', 'spt_srikandi_version_sha256_index');
            $table->foreign('workflow_id', 'spt_srikandi_version_workflow_foreign')
                ->references('id')->on('spt_srikandi_workflows')->cascadeOnDelete();
            $table->foreign('prepared_by', 'spt_srikandi_version_prepared_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('submitted_by', 'spt_srikandi_version_submitted_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('revision_requested_by', 'spt_srikandi_version_revision_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spt_srikandi_versions');
    }
};
