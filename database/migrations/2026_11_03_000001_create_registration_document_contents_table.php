<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_document_contents', function (Blueprint $table) {
            $table->foreignId('registration_document_id')->primary()->constrained()->cascadeOnDelete();
            $table->longText('encrypted_content');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_document_contents');
    }
};
