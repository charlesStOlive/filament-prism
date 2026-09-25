<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le suivi d'un appel qui peut durer (une image générée en arrière-plan) et
 * ce qu'il a coûté : de quoi dire où en est une demande, et tirer des stats
 * par ressource (durée, échecs, coût) sans compteur séparé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('finished_at')->nullable()->after('started_at');
            $table->text('error')->nullable()->after('finished_at');
            $table->decimal('cost', 12, 6)->nullable()->after('total_tokens');
            $table->index(['task', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table): void {
            $table->dropIndex(['task', 'created_at']);
            $table->dropIndex(['status']);
            $table->dropColumn(['started_at', 'finished_at', 'error', 'cost']);
        });
    }
};
