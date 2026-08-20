<?php

namespace AwtTechnology\FilamentAttachmentLibrary;

use AwtTechnology\FilamentAttachmentLibrary\Console\Commands\ClearGlide;
use AwtTechnology\FilamentAttachmentLibrary\Console\Commands\GlideStats;
use AwtTechnology\FilamentAttachmentLibrary\Console\Commands\InstallCommand;
use AwtTechnology\FilamentAttachmentLibrary\Console\Commands\WarmGlide;
use AwtTechnology\FilamentAttachmentLibrary\Filament\Pages\AttachmentLibrary;
use AwtTechnology\FilamentAttachmentLibrary\Glide\GlideManager;
use AwtTechnology\FilamentAttachmentLibrary\Glide\Resizer;
use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentInfo;
use AwtTechnology\FilamentAttachmentLibrary\Livewire\StoreCroppedAttachment;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;
use AwtTechnology\FilamentAttachmentLibrary\Observers\AttachmentObserver;
use AwtTechnology\FilamentAttachmentLibrary\View\Components\Image;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use League\Glide\Server;
use Livewire\Livewire;

class AttachmentLibraryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/attachment-library.php', 'attachment-library');
        $this->mergeConfigFrom(__DIR__.'/../config/glide.php', 'glide');
        $this->mergeConfigFrom(__DIR__.'/../config/filament-attachment-library.php', 'filament-attachment-library');

        // Merge glide symlinks into the filesystem links config.
        Config::set('filesystems.links', array_merge(
            Config::get('filesystems.links', []),
            Config::get('glide.links', [])
        ));

        // Bind attachment manager (reads class from config so apps can swap it).
        $attachmentManagerClass = Config::get(
            'attachment-library.class_mapping.attachment_manager',
            AttachmentManager::class
        );
        $this->app->bind('attachment.manager', $attachmentManagerClass);

        // Bind Glide services. GlideManager is a singleton so its memoized Glide
        // server (and resolved Flysystem drivers) is reused across a request.
        $this->app->singleton('attachment.glide.manager', GlideManager::class);
        $this->app->bind(Server::class, fn () => app('attachment.glide.manager')->server());
        $this->app->bind('attachment.resizer', fn () => new Resizer(config('glide.sizes')));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-attachment-library');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filament-attachment-library');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->publishes([
            __DIR__.'/../config/attachment-library.php' => config_path('attachment-library.php'),
            __DIR__.'/../config/glide.php' => config_path('glide.php'),
            __DIR__.'/../config/filament-attachment-library.php' => config_path('filament-attachment-library.php'),
        ], 'filament-attachment-library-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/filament-attachment-library'),
        ], 'filament-attachment-library-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/filament-attachment-library'),
        ], 'filament-attachment-library-translations');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'filament-attachment-library-migrations');

        // Register Blade view component for responsive images.
        Blade::component('filament-attachment-library-image', Image::class);

        // Register Livewire components.
        Livewire::component('attachment-browser', AttachmentBrowser::class);
        Livewire::component('attachment-info', AttachmentInfo::class);
        Livewire::component('attachment-cropper', StoreCroppedAttachment::class);

        // Vendored Croppie assets for the CroppieAttachmentField. loadedOnRequest()
        // keeps them out of every page; the field's blade injects them on demand
        // (Croppie is a UMD global, so it is loaded via a classic <script>, not the
        // ESM asset loader which would break its window.Croppie assignment).
        FilamentAsset::register([
            Css::make('croppie', __DIR__.'/../resources/dist/croppie.css')->loadedOnRequest(),
            Js::make('croppie', __DIR__.'/../resources/dist/croppie.min.js')->loadedOnRequest(),
        ], package: 'awttechnology/filament-attachment-library');

        // Observe attachment model.
        $attachmentClass = Config::get('attachment-library.class_mapping.attachment', Attachment::class);
        $attachmentClass::observe(AttachmentObserver::class);

        // Inject the attachment browser modal at the end of every Filament page.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_END,
            fn () => view('filament-attachment-library::components.attachment-browser-modal', [
                'basePath' => AttachmentLibrary::getBasePath(),
            ]),
        );

        // Expose clipboard labels to JS.
        FilamentAsset::registerScriptData([
            'fal' => [
                'labels' => [
                    'clipboardSuccess' => __('filament-attachment-library::notifications.clipboard.success'),
                ],
            ],
        ]);

        $this->commands([InstallCommand::class, ClearGlide::class, GlideStats::class, WarmGlide::class]);
    }
}
