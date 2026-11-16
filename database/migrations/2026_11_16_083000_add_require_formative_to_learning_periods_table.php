<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('learning_periods', function (Blueprint $table) {
            $table->boolean('require_formative_assessment')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('learning_periods', function (Blueprint $table) {
            $table->dropColumn('require_formative_assessment');
        });
    }
};
