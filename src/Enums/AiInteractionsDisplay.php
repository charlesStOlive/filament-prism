<?php

namespace CharlesStOlive\FilamentPrism\Enums;

/**
 * Où s'ouvrent les demandes IA d'un modèle (voir `AiInteractionsAction::display()`) :
 * une modale au centre, un slide-over qui glisse par-dessus la page, ou le volet
 * latéral de filament-ui, posé à côté du formulaire qui reste utilisable.
 */
enum AiInteractionsDisplay: string
{
    case Modal = 'modal';
    case SlideOver = 'slide-over';
    case SidePane = 'side-pane';
}
