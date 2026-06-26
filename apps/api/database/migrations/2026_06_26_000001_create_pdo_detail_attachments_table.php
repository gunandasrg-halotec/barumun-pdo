<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdo_detail_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pdo_detail_id')->constrained('pdo_details')->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->string('original_filename');
            $table->string('disk_path');
            $table->string('disk', 20)->default('s3');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdo_detail_attachments');
    }
};
