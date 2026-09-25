<?php

return [
    /*
     * Provider et modèle par défaut pour toute tâche qui ne précise pas les siens.
     * Les providers eux-mêmes (clés API, urls...) sont configurés côté
     * prism-php/prism, dans config/prism.php de l'application.
     */
    'provider' => env('FILAMENT_PRISM_PROVIDER', env('AI_PROVIDER', 'openai')),
    'model' => env('FILAMENT_PRISM_MODEL', env('AI_MODEL', 'gpt-5.4-nano')),

    /*
     * Le modèle par défaut d'une ressource qui produit des images
     * (`AiResource::generatesImages()`), et où ces images sont rangées :
     * `<directory>/<id de la demande>/<n>.<format>` sur ce disque.
     */
    'images' => [
        'model' => env('FILAMENT_PRISM_IMAGE_MODEL', 'gpt-image-1'),
        'disk' => env('FILAMENT_PRISM_IMAGE_DISK', 'public'),
        'directory' => 'ai-images',
    ],

    /*
     * Les demandes mises en file (`AiResource::queued()`), traitées par un
     * worker (`php artisan queue:work`). `timeout` : le temps laissé à une
     * demande, en secondes — le `retry_after` de la connexion de queue doit le
     * dépasser, sinon une demande encore en cours serait relancée (et payée)
     * une seconde fois.
     */
    'queue' => [
        'connection' => env('FILAMENT_PRISM_QUEUE_CONNECTION'),
        'name' => env('FILAMENT_PRISM_QUEUE'),
        'timeout' => (int) env('FILAMENT_PRISM_QUEUE_TIMEOUT', 300),
    ],

    /*
     * Prévenir l'auteur d'une demande mise en file quand elle est prête (ou a
     * échoué), dans les notifications Filament en base — le panel doit les
     * activer (`->databaseNotifications()`, table `notifications`).
     */
    'notify' => env('FILAMENT_PRISM_NOTIFY', true),

    /*
     * Les fournisseurs d'IA et leurs modèles.
     *
     * - `currency` : la devise des prix et de la facture du fournisseur ; tout
     *   est converti en euros au taux de référence BCE du jour (voir
     *   `filament-prism:sync-billing`).
     * - `billing` : où lire ce que le fournisseur a réellement facturé. Il faut
     *   une clé **admin** (pas la clé d'API des appels) ; le projet / workspace
     *   restreint la facture à cette application — sans lui, c'est celle de
     *   toute l'organisation.
     * - `models` : pour chaque modèle, son `type` (text, image...) et ses prix
     *   pour un million de tokens — `input`, `output`, et pour un modèle
     *   d'image `input_image` (les photos jointes, comptées à part). Un modèle
     *   absent a un coût inconnu, pas nul. Prix du tarif standard, à tenir à
     *   jour depuis la page de prix du fournisseur (relevés le 25/09/2026).
     */
    'providers' => [
        'openai' => [
            'label' => 'OpenAI',
            'currency' => 'USD',
            'billing' => [
                'source' => \CharlesStOlive\FilamentPrism\Billing\OpenAiBilling::class,
                'admin_key' => env('OPENAI_ADMIN_KEY'),
                'project_id' => env('OPENAI_PROJECT_ID'),
            ],
            'models' => [
                'gpt-5.4' => ['type' => 'text', 'input' => 2.50, 'output' => 15.00],
                'gpt-5.4-mini' => ['type' => 'text', 'input' => 0.75, 'output' => 4.50],
                'gpt-5.4-nano' => ['type' => 'text', 'input' => 0.20, 'output' => 1.25],
                'gpt-image-1' => ['type' => 'image', 'input' => 5.00, 'input_image' => 10.00, 'output' => 40.00],
                'gpt-image-1-mini' => ['type' => 'image', 'input' => 2.00, 'input_image' => 2.50, 'output' => 8.00],
                'gpt-image-1.5' => ['type' => 'image', 'input' => 5.00, 'input_image' => 8.00, 'output' => 32.00],
            ],
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'currency' => 'USD',
            'billing' => [
                'source' => \CharlesStOlive\FilamentPrism\Billing\AnthropicBilling::class,
                'admin_key' => env('ANTHROPIC_ADMIN_KEY'),
                'workspace_id' => env('ANTHROPIC_WORKSPACE_ID'),
            ],
            'models' => [],
        ],
    ],

    /*
     * Les ressources IA enregistrées : chacune une classe qui étend AiResource.
     * Une application ajoute les siennes en republiant/éditant ce fichier,
     * sans toucher au package.
     */
    'tasks' => [
        \CharlesStOlive\FilamentPrism\Tasks\OrthographyTask::class,
    ],
];
