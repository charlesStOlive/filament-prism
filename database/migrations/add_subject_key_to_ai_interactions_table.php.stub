<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table): void {
            $table->string('subject_key')->nullable()->after('correctable_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table): void {
            $table->dropColumn('subject_key');
        });
    }
};
