<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('topics')->cascadeOnDelete();
            $table->string('code')->nullable();
            $table->text('objective');
            $table->text('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->enum('status', [
                'active',
                'inactive'
            ])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index([
                'topic_id',
                'status'
            ]);

            $table->index([
                'topic_id',
                'order'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_objectives');
    }
};