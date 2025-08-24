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
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['quiz','exam','homework', 'practice']);
            $table->string('title');
            $table->unsignedBigInteger('creator_id');
            $table->unsignedBigInteger('topic_id');
            $table->integer('question_count');
            $table->timestamp('due_date')->nullable();
            $table->boolean('is_timed')->default(false);
            $table->integer('time_limit')->nullable(); // in minutes
            $table->enum('delivery_mode', ['online', 'offline'])->default('offline')->after('question_count')->comment('Mode of delivery for the assessment: online or offline');
            $table->timestamps();

             $table->foreign('creator_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('topic_id')->references('id')->on('topics')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
