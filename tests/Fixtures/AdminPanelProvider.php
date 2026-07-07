<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures;

use AwtTechnology\FilamentAttachmentLibrary\FilamentAttachmentLibrary;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(FilamentAttachmentLibrary::make());
    }
}
