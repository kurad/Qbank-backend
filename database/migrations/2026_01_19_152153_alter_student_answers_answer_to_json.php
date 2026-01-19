<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_answers', function (Blueprint $table) {
            // If you already have data, keep it nullable to avoid migration failures
            $table->json('answer')->nullable()->change();
            $table->boolean('is_correct')->default(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_answers', function (Blueprint $table) {
            $table->text('answer')->nullable(false)->change();
            $table->boolean('is_correct')->default(false)->change();
        });
    }
};
