<?php

namespace CharlesStOlive\FilamentPrism\Support;

use CharlesStOlive\FilamentPrism\Models\AiExchangeRate;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Les euros : les taux de référence publiés chaque jour ouvré par la BCE
 * (gratuit, sans clé), gardés en base (`ai_exchange_rates`). Un montant se
 * convertit au taux du jour — ou, un week-end ou un jour férié, au dernier
 * publié avant.
 *
 * Rien n'est téléchargé pendant un appel à l'IA : `sync()` relève les taux (la
 * commande `filament-prism:sync-billing`), `toEur()` ne lit que la base, et
 * rend `null` tant qu'aucun taux n'est connu.
 */
class ExchangeRates
{
    public const ECB_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml';

    /** @var array<string, float|null> */
    private array $cache = [];

    /** Relève les taux des 90 derniers jours ; renvoie le nombre de taux enregistrés. */
    public function sync(): int
    {
        $xml = new SimpleXMLElement(Http::timeout(30)->get(self::ECB_URL)->throw()->body());
        $rows = [];

        foreach ($xml->Cube->Cube as $day) {
            foreach ($day->Cube as $rate) {
                $rows[] = ['date' => (string) $day['time'], 'currency' => (string) $rate['currency'], 'rate' => (float) $rate['rate']];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            AiExchangeRate::query()->upsert($chunk, ['date', 'currency'], ['rate']);
        }

        $this->cache = [];

        return count($rows);
    }

    public function toEur(float $amount, string $currency, DateTimeInterface $date): ?float
    {
        $currency = strtoupper($currency);

        if ($currency === 'EUR') {
            return $amount;
        }

        $rate = $this->rate($currency, $date);

        return $rate === null ? null : round($amount / $rate, AiMoney::PRECISION);
    }

    /** 1 EUR = ? dans la devise, le jour dit ou le dernier publié avant (à défaut, le premier connu après). */
    public function rate(string $currency, DateTimeInterface $date): ?float
    {
        $day = Carbon::instance($date)->toDateString();
        $key = $currency.'@'.$day;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            $rate = AiExchangeRate::query()->where('currency', $currency)->whereDate('date', '<=', $day)->orderByDesc('date')->value('rate')
                ?? AiExchangeRate::query()->where('currency', $currency)->orderBy('date')->value('rate');
        } catch (Throwable) {
            $rate = null; // La table n'existe pas encore (migration à lancer) : pas d'euros, pas d'erreur.
        }

        return $this->cache[$key] = $rate === null ? null : (float) $rate;
    }
}
