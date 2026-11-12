<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('objective_support_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_objective_id')->unique()->constrained('learning_objectives')->cascadeOnDelete();
            $table->longText('content');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('objective_support_notes'); }
};
