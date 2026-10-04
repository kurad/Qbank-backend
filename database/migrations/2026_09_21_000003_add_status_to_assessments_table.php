<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            // Existing assessments remain usable; new builder drafts explicitly set draft.
            $table->enum('status', ['draft', 'published', 'archived'])
                ->default('published')
                ->after('instructions');
            $table->index(['creator_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropIndex(['creator_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
