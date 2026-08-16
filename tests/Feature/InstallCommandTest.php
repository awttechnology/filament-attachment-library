<?php

use Illuminate\Support\Facades\File;

/**
 * Collect the migration files this command is responsible for, so each test can
 * assert against and clean up only its own output (the testbench skeleton's
 * migrations directory is shared across the suite).
 */
function publishedAttachmentMigrations(): array
{
    $names = [
        'create_attachments_table',
        'create_attachables_table',
        'add_focal_point_to_attachments_table',
        'add_indexes_to_attachments_table',
        'add_collection_to_attachables_table',
        'add_order_to_attachables_table',
    ];

    $files = [];

    foreach ($names as $name) {
        $files = array_merge($files, File::glob(database_path("migrations/*_{$name}.php")));
    }

    return $files;
}

afterEach(function () {
    File::delete(publishedAttachmentMigrations());
});

it('publishes the migrations in dependency order', function () {
    $this->artisan('filament-attachment-library:install', ['--no-migrate' => true])
        ->assertSuccessful();

    $published = collect(publishedAttachmentMigrations())
        ->map(fn ($path) => basename($path))
        ->sort()
        ->values();

    expect($published)->toHaveCount(6);

    // Sorting by filename (timestamp prefix) must yield dependency order:
    // attachments before attachables, creates before their alters.
    expect($published[0])->toContain('create_attachments_table')
        ->and($published[1])->toContain('create_attachables_table')
        ->and($published->last())->toContain('add_order_to_attachables_table');
});

it('is idempotent and skips already published migrations', function () {
    $this->artisan('filament-attachment-library:install', ['--no-migrate' => true])->assertSuccessful();

    $firstRun = publishedAttachmentMigrations();

    $this->artisan('filament-attachment-library:install', ['--no-migrate' => true])
        ->expectsOutputToContain('Published 0 migration(s), skipped 6.')
        ->assertSuccessful();

    // No duplicate files were created on the second run.
    expect(publishedAttachmentMigrations())->toEqualCanonicalizing($firstRun);
});
