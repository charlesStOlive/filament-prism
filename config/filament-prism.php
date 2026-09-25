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
     * Les ressources IA enregistrées : chacune une classe qui étend AiResource.
     * Une application ajoute les siennes en republiant/éditant ce fichier,
     * sans toucher au package.
     */
    'tasks' => [
        \CharlesStOlive\FilamentPrism\Tasks\OrthographyTask::class,
    ],
];
