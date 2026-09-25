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
     * Prix pour un million de tokens, par modèle, dans une même devise
     * (`currency`) : `input`, `output`, et pour un modèle d'image `input_image`
     * (les photos jointes, comptées à part quand le provider en donne le
     * détail). Un modèle absent n'a pas de coût : les stats le disent inconnu.
     * Tarifs à reprendre de la page de prix du provider.
     */
    'currency' => env('FILAMENT_PRISM_CURRENCY', 'USD'),

    'pricing' => [
        // 'gpt-image-1' => ['input' => <prix>, 'input_image' => <prix>, 'output' => <prix>],
        // 'gpt-5.4-nano' => ['input' => <prix>, 'output' => <prix>],
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
