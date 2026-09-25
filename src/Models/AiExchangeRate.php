<?php

namespace CharlesStOlive\FilamentPrism\Models;

use Illuminate\Database\Eloquent\Model;

/** Un taux de référence de la BCE : 1 EUR = `rate` dans `currency`, le jour `date`. Voir `Support\ExchangeRates`. */
class AiExchangeRate extends Model
{
    public $timestamps = false;

    protected $fillable = ['date', 'currency', 'rate'];

    protected $casts = [
        'date' => 'date',
        'rate' => 'decimal:6',
    ];
}
