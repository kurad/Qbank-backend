<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_support_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('learning_period_id')->nullable()->constrained('learning_periods')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained('topics')->nullOnDelete();
            $table->string('type', 30)->default('feedback');
            $table->string('status', 30)->default('open');
            $table->string('subject')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index(['teacher_id', 'status']);
        });

        Schema::create('learning_support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('learning_support_threads')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('learning_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('learning_support_threads')->cascadeOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 30)->default('scheduled');
            $table->text('teacher_notes')->nullable();
            $table->text('student_notes')->nullable();
            $table->boolean('follow_up_required')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_check_ins');
        Schema::dropIfExists('learning_support_messages');
        Schema::dropIfExists('learning_support_threads');
    }
};
