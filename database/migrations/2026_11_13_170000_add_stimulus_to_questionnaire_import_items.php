<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('questionnaire_import_items', 'stimulus')) {
            Schema::table('questionnaire_import_items', function (Blueprint $table) {
                $table->json('stimulus')->nullable();
            });
        }
    }
    public function down(): void {
        if (Schema::hasColumn('questionnaire_import_items', 'stimulus')) {
            Schema::table('questionnaire_import_items', function (Blueprint $table) {
                $table->dropColumn('stimulus');
            });
        }
    }
};
