<?php

use AwtTechnology\FilamentAttachmentLibrary\AttachmentManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('attachment-library.directory_source', 'database');
    config()->set('attachment-library.merge_storage_directories', true);
    config()->set('attachment-library.hidden_directories', ['.glide', 'remote']);
});

it('surfaces storage folders that have no attachment rows', function () {
    // A DB-backed folder (makeAttachment writes docs/<file> and inserts a row)…
    makeAttachment(['path' => 'docs', 'name' => 'a']);
    // …and a folder that exists only on the storage disk, with no attachment rows.
    Storage::disk('attachments')->makeDirectory('empty-folder');

    $directories = (new AttachmentManager())->directories(null);

    expect($directories->pluck('fullPath')->all())
        ->toContain('docs')
        ->toContain('empty-folder');
})->group('directories');

it('does not duplicate a folder present in both the database and on storage', function () {
    // 'docs' exists as both a DB path and a real storage folder (the file write).
    makeAttachment(['path' => 'docs', 'name' => 'a']);

    $directories = (new AttachmentManager())->directories(null);

    expect($directories->pluck('fullPath')->filter(fn ($p) => $p === 'docs'))->toHaveCount(1);
})->group('directories');

it('excludes hidden storage folders such as .glide and remote', function () {
    Storage::disk('attachments')->makeDirectory('.glide');
    Storage::disk('attachments')->makeDirectory('remote');
    Storage::disk('attachments')->makeDirectory('gallery');

    $directories = (new AttachmentManager())->directories(null);

    expect($directories->pluck('fullPath')->all())
        ->toContain('gallery')
        ->not->toContain('.glide')
        ->not->toContain('remote');
})->group('directories');

it('does not merge storage folders when the toggle is off', function () {
    config()->set('attachment-library.merge_storage_directories', false);
    Storage::disk('attachments')->makeDirectory('empty-folder');

    $directories = (new AttachmentManager())->directories(null);

    expect($directories->pluck('fullPath')->all())->not->toContain('empty-folder');
})->group('directories');

it('degrades to database directories when the storage listing fails', function () {
    makeAttachment(['path' => 'docs', 'name' => 'a']);

    $manager = new class extends AttachmentManager
    {
        protected function getFilesystem(): Filesystem
        {
            throw new RuntimeException('storage down');
        }
    };

    $directories = $manager->directories(null);

    expect($directories->pluck('fullPath')->all())->toContain('docs');
})->group('directories');
