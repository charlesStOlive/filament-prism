<?php

namespace CharlesStOlive\FilamentPrism\Commands;

use CharlesStOlive\FilamentPrism\Billing\AiBillingSync;
use Illuminate\Console\Command;

/**
 * Relève les taux BCE et ce que les fournisseurs ont facturé ces derniers jours (voir `AiBillingSync`).
 * À planifier chaque jour dans l'application :
 *
 *     Schedule::command('filament-prism:sync-billing')->dailyAt('06:00');
 */
class SyncBillingCommand extends Command
{
    protected $signature = 'filament-prism:sync-billing {--days=7 : Les jours relevés, aujourd’hui compris (31 au plus par page d’API)}';

    protected $description = 'Relève les taux de change BCE et les coûts facturés par les fournisseurs d’IA';

    public function handle(AiBillingSync $sync): int
    {
        $result = $sync->sync((int) $this->option('days'));

        $this->line('Taux BCE : '.$result['rates']);

        if ($result['providers'] === []) {
            $this->warn('Aucun fournisseur à relever : une clé admin de facturation manque (ex. OPENAI_ADMIN_KEY).');
        }

        foreach ($result['providers'] as $provider => $outcome) {
            $this->line("{$provider} : ".(is_int($outcome) ? "{$outcome} ligne(s) de facture" : $outcome));
        }

        $this->line("Demandes converties en euros : {$result['interactions']}");

        return self::SUCCESS;
    }
}
