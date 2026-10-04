<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_subject_id')->constrained('grade_subjects')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->foreignId('created_by') ->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['grade_subject_id', 'status']);
            $table->index(['grade_subject_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};