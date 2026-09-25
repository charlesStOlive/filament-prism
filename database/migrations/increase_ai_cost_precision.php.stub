<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Huit décimales plutôt que six pour les coûts : une petite requête (quelques
 * dizaines de tokens d'un modèle bon marché) coûte quelques millionièmes
 * d'euro, que six décimales arrondiraient à un ou deux chiffres (voir `AiMoney`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table): void {
            $table->decimal('cost', 16, 8)->nullable()->change();
            $table->decimal('cost_eur', 16, 8)->nullable()->change();
        });

        Schema::table('ai_billed_costs', function (Blueprint $table): void {
            $table->decimal('amount', 16, 8)->change();
            $table->decimal('amount_eur', 16, 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table): void {
            $table->decimal('cost', 12, 6)->nullable()->change();
            $table->decimal('cost_eur', 12, 6)->nullable()->change();
        });

        Schema::table('ai_billed_costs', function (Blueprint $table): void {
            $table->decimal('amount', 14, 6)->change();
            $table->decimal('amount_eur', 14, 6)->nullable()->change();
        });
    }
};
