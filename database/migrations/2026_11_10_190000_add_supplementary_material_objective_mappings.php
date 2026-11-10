<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('course_materials', 'purpose')) {
            Schema::table('course_materials', function (Blueprint $table) {
                $table->string('purpose', 24)->default('curriculum')->after('scope');
            });
        }
        if (!Schema::hasTable('course_material_objective_supports')) {
            Schema::create('course_material_objective_supports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_material_id')->constrained('course_materials')->cascadeOnDelete();
                $table->foreignId('learning_objective_id')->constrained('learning_objectives')->cascadeOnDelete();
                $table->foreignId('mapped_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['course_material_id', 'learning_objective_id'], 'cm_objective_support_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_material_objective_supports');
        if (Schema::hasColumn('course_materials', 'purpose')) {
            Schema::table('course_materials', fn (Blueprint $table) => $table->dropColumn('purpose'));
        }
    }
};
