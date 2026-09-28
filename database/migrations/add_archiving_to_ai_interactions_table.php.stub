<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les états des demandes deviennent des états Spatie (voir `AiInteractionStatus`) :
 * - « appliquée » devient « acceptée » (`accepted`), l'issue positive de toute
 *   demande ; `applied_at` dit toujours si le résultat a aussi été appliqué ;
 * - une demande terminée peut être archivée : `archived_at`, et `archived_from`,
 *   l'issue qu'elle avait (pour les statistiques, et pour désarchiver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('applied_at');
            $table->string('archived_from')->nullable()->after('archived_at');
        });

        DB::table('ai_interactions')->where('status', 'applied')->update(['status' => 'accepted']);
    }

    public function down(): void
    {
        DB::table('ai_interactions')->where('status', 'archived')->update(['status' => DB::raw('archived_from')]);
        DB::table('ai_interactions')->where('status', 'accepted')->update(['status' => 'applied']);

        Schema::table('ai_interactions', function (Blueprint $table): void {
            $table->dropColumn(['archived_at', 'archived_from']);
        });
    }
};
