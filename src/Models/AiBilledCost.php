<?php

namespace CharlesStOlive\FilamentPrism\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ce qu'un fournisseur a réellement facturé un jour donné, pour une ligne de
 * sa facture (un modèle et un type de tokens, le plus souvent), relevé par son
 * API de facturation (voir `Billing\AiBillingSync`). `amount` dans la devise
 * du fournisseur, `amount_eur` au taux BCE du jour.
 */
class AiBilledCost extends Model
{
    protected $fillable = ['provider', 'date', 'project_id', 'line_item', 'model', 'amount', 'currency', 'amount_eur'];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:8',
        'amount_eur' => 'decimal:8',
    ];
}
