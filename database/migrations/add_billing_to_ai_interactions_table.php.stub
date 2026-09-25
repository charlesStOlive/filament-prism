<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que coûtent vraiment les appels IA, en euros :
 * - sur chaque demande, le type de modèle (`kind` : text, structured, image),
 *   la devise de son coût estimé et ce coût en euros (`cost_eur`) ;
 * - `ai_billed_costs` : ce que le fournisseur a réellement facturé, jour par
 *   jour, relevé par son API de facturation (voir `Billing\AiBillingSync`) ;
 * - `ai_exchange_rates` : les taux de référence de la BCE (1 EUR = `rate`
 *   dans la devise), pour convertir l'un et l'autre au taux du jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table): void {
            $table->string('kind', 20)->nullable()->after('model');
            $table->string('currency', 3)->nullable()->after('cost');
            $table->decimal('cost_eur', 12, 6)->nullable()->after('currency');
            $table->index(['provider', 'created_at']);
        });

        Schema::create('ai_billed_costs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->date('date');
            // Chaînes vides plutôt que null : un index unique ne dédoublonne pas les null.
            $table->string('project_id', 100)->default('');
            $table->string('line_item', 191)->default('');
            $table->string('model', 100)->nullable();
            $table->decimal('amount', 14, 6);
            $table->string('currency', 3);
            $table->decimal('amount_eur', 14, 6)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'date', 'project_id', 'line_item'], 'ai_billed_costs_unique');
        });

        Schema::create('ai_exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('currency', 3);
            $table->decimal('rate', 14, 6);
            $table->unique(['date', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_exchange_rates');
        Schema::dropIfExists('ai_billed_costs');

        Schema::table('ai_interactions', function (Blueprint $table): void {
            $table->dropIndex(['provider', 'created_at']);
            $table->dropColumn(['kind', 'currency', 'cost_eur']);
        });
    }
};
