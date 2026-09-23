<?php

namespace CharlesStOlive\FilamentPrism\Support;

use RuntimeException;
use Throwable;

/**
 * L'appel à l'IA a échoué — clé API absente ou refusée, réseau, quota... Un
 * type distinct plutôt que de laisser fuir l'exception brute de Prism/du
 * client HTTP : `CorrectionAction`/`GroupCorrectionAction` la rattrapent pour
 * afficher un message propre dans la modale plutôt qu'un plantage.
 *
 * Le message est déjà sûr à montrer à l'utilisateur (voir
 * `CorrectionService::callAi()`) ; l'exception d'origine est toujours
 * signalée (`report()`) pour rester consultable dans les logs.
 */
class AiProviderException extends RuntimeException
{
    public static function fromThrowable(Throwable $previous): self
    {
        report($previous);

        return new self(
            "L'appel à l'IA a échoué. Vérifiez la configuration du provider (clé API, quota, connexion) et réessayez.",
            previous: $previous,
        );
    }
}
