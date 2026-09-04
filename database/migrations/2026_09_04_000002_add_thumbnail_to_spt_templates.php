<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spt_templates', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable()->after('file_sha256');
            $table->string('thumbnail_sha256', 64)->nullable()->after('thumbnail_path');
        });
    }

    public function down(): void
    {
        Schema::table('spt_templates', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_sha256', 'thumbnail_path']);
        });
    }
};