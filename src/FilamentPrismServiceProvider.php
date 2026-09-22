<?php

namespace CharlesStOlive\FilamentPrism;

use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Services\CorrectionService;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentPrismServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-prism')
            ->hasConfigFile('filament-prism')
            ->hasViews('filament-prism')
            ->hasMigrations([
                'create_filament_prism_interactions_table',
                'add_subject_key_to_ai_interactions_table',
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AiTaskRegistry::class);
        $this->app->singleton(CorrectionService::class);
    }
}
