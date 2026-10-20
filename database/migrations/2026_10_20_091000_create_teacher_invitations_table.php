<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'accepted', 'revoked', 'expired'])->default('pending');
            $table->timestamp('expires_at');
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('previous_school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->boolean('transfer_confirmed')->default(false);
            $table->timestamps();

            $table->index(['school_id', 'status'], 'ti_school_status_idx');
            $table->index(['email', 'status'], 'ti_email_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_invitations');
    }
};
