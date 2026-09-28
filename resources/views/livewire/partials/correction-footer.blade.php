{{--
    Le pied d'une revue de correction, selon l'état de sa demande (voir ReviewsCorrection) :
    brouillon → Soumettre ; à vérifier → Affiner, Ignorer, Appliquer ; échec → Relancer ; sinon l'issue.
--}}
@php($statuses = \CharlesStOlive\FilamentPrism\Models\AiInteraction::class)
<div class="fp-review-footer">
    @if ($interaction->isDraft())
        <span class="fp-review-footer-start fp-muted">Rien n’a encore été envoyé à l’IA.</span>

        <x-filament::button icon="heroicon-m-paper-airplane" wire:click="submit">
            Soumettre à l’IA
        </x-filament::button>
    @elseif ($interaction->isStatus($statuses::STATUS_PENDING))
        <div x-data="{ refining: false }" class="fp-review-footer-start fp-review-refine">
            <x-filament::button color="gray" icon="heroicon-m-adjustments-horizontal" x-show="! refining" x-on:click="refining = true">
                Affiner
            </x-filament::button>

            <div x-show="refining" x-cloak class="fp-review-refine-form">
                <textarea
                    wire:model="feedback"
                    rows="2"
                    placeholder="Ce qui ne va pas — par exemple : « garde le tutoiement », « ne touche pas aux noms propres »."
                    class="fp-review-refine-input"
                ></textarea>

                <div class="fp-review-refine-buttons">
                    <x-filament::button size="sm" icon="heroicon-m-paper-airplane" wire:click="refine">
                        Soumettre la remarque
                    </x-filament::button>

                    <x-filament::button size="sm" color="gray" x-on:click="refining = false">
                        Annuler
                    </x-filament::button>
                </div>
            </div>
        </div>

        <x-filament::button color="gray" wire:click="discard">
            Ignorer
        </x-filament::button>

        <x-filament::button color="gray" outlined wire:click="applySelected">
            Appliquer la sélection
        </x-filament::button>

        <x-filament::button wire:click="applyAll">
            Tout appliquer
        </x-filament::button>
    @elseif ($interaction->isStatus($statuses::STATUS_FAILED))
        <x-filament::button icon="heroicon-m-arrow-path" wire:click="retry">
            Relancer
        </x-filament::button>
    @else
        {{-- Appliquée, ignorée, affinée… : la revue le dit, et ne propose plus de le refaire. --}}
        <x-filament::badge :color="$interaction->status->getColor()" :icon="$interaction->status->getIcon()">
            {{ match (true) {
                $interaction->isStatus($statuses::STATUS_ACCEPTED) => 'Correction appliquée',
                $interaction->isStatus($statuses::STATUS_DISCARDED) => 'Correction ignorée',
                default => $interaction->statusLabel(),
            } }}
        </x-filament::badge>
    @endif
</div>
