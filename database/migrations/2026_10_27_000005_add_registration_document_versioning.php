<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_documents', function (Blueprint $table) {
            // A replacement points to the prior version; RESTRICT preserves the
            // prior database record while a newer version depends on it.
            $table->foreignId('supersedes_document_id')
                ->nullable()
                ->constrained('registration_documents', 'id', 'registration_document_supersedes_fk')
                ->restrictOnDelete();
            $table->timestamp('superseded_at')->nullable();

            $table->unique(
                'supersedes_document_id',
                'registration_document_single_successor_unique'
            );

            $table->index(
                ['resident_registration_id', 'superseded_at'],
                'registration_documents_version_index'
            );
        });
    }

    public function down(): void
    {
        if (DB::table('registration_documents')->where(function ($query) {
            $query->whereNotNull('supersedes_document_id')
                ->orWhereNotNull('superseded_at');
        })->exists()) {
            throw new RuntimeException(
                'Refusing to remove registration document version links while version data is present.'
            );
        }

        Schema::table('registration_documents', function (Blueprint $table) {
            $table->dropIndex('registration_documents_version_index');
            $table->dropUnique('registration_document_single_successor_unique');
            $table->dropForeign('registration_document_supersedes_fk');
            $table->dropColumn(['supersedes_document_id', 'superseded_at']);
        });
    }
};
