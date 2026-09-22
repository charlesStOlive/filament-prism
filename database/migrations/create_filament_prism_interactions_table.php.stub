<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task');
            $table->string('provider');
            $table->string('model');
            $table->nullableMorphs('correctable');
            $table->nullableMorphs('trackable');
            $table->json('input');
            $table->json('output')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('applied_at')->nullable();
            $table->uuid('thread_id')->nullable()->index();
            $table->foreignId('parent_interaction_id')->nullable()->constrained('ai_interactions')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_interactions');
    }
};
