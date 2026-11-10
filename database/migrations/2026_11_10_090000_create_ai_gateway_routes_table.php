<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_gateway_routes', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 80)->unique(); // global, tutor, question_generation etc.
            $table->json('providers'); // ordered enabled provider keys
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_gateway_routes');
    }
};
