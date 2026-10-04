<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_analysis_topics', function (Blueprint $table) {
            $table->id();

            $table->foreignId('curriculum_analysis_id')
                ->constrained('curriculum_analyses')
                ->cascadeOnDelete();

            $table->string('name');

            $table->text('description')->nullable();

            $table->unsignedInteger('order')->default(0);

            $table->enum('status', [
                'proposed',
                'approved',
                'rejected',
            ])->default('proposed');

            $table->timestamps();

            $table->index([
                'curriculum_analysis_id',
                'order',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_analysis_topics');
    }
};