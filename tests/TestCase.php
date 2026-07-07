<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Tests;

use AwtTechnology\FilamentAttachmentLibrary\AttachmentLibraryServiceProvider;
use AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures\AdminPanelProvider;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected $enablesPackageDiscoveries = true;

    /**
     * Whether the Testbench default skeleton has been linked to this
     * package's own vendor/ directory in the current process.
     */
    private static bool $vendorSymlinked = false;

    protected function setUp(): void
    {
        $this->ensureVendorIsSymlinked();

        parent::setUp();

        Storage::fake('attachments');
        $this->runPackageMigrations();

        $this->app['view']->addLocation(__DIR__ . '/Fixtures/views');
    }

    /**
     * Testbench's default skeleton (vendor/orchestra/testbench-core/laravel)
     * ships without a vendor/ directory of its own and with an empty
     * bootstrap/cache/packages.php. Laravel's PackageManifest only rebuilds
     * that cache when the file is missing, so without linking the skeleton's
     * vendor/ to this package's real vendor/ directory, package discovery
     * (Livewire, Filament, ...) silently resolves to zero providers.
     *
     * This mirrors what `vendor/bin/testbench package:discover` does for a
     * consuming application, but runs it once automatically so `composer
     * test` works out of the box after `composer update`.
     */
    private function ensureVendorIsSymlinked(): void
    {
        if (self::$vendorSymlinked) {
            return;
        }

        TestbenchApplication::createVendorSymlink(null, dirname(__DIR__) . '/vendor');

        self::$vendorSymlinked = true;
    }

    protected function getPackageProviders($app): array
    {
        return [
            AttachmentLibraryServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('attachment-library.disk', 'attachments');
        $app['config']->set('glide.source', 'attachments');
        $app['config']->set('glide.cache_disk', 'attachments');
    }

    protected function runPackageMigrations(): void
    {
        $stubs = [
            'create_attachments_table',
            'create_attachables_table',
            'add_collection_to_attachables_table',
            'add_order_to_attachables_table',
            'add_focal_point_to_attachments_table',
            'add_indexes_to_attachments_table',
        ];

        foreach ($stubs as $stub) {
            $migration = include __DIR__ . "/../database/migrations/{$stub}.php.stub";
            $migration->up();
        }
    }
}
