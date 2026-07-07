<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use Livewire\Livewire;

it('falls back to the default page size for invalid values', function () {
    Livewire::withQueryParams(['pageSize' => 999])
        ->test(AttachmentBrowser::class)
        ->assertSet('pageSize', 25);
});

it('lists only attachments from the configured disk', function () {
    makeAttachment(['name' => 'mine']);
    makeAttachment(['name' => 'foreign', 'disk' => 'other']);

    Livewire::test(AttachmentBrowser::class)
        ->assertSee('mine')
        ->assertDontSee('foreign');
});

it('counts directory items only on the configured disk', function () {
    config()->set('attachment-library.directory_source', 'database');
    makeAttachment(['path' => 'docs', 'name' => 'a']);
    makeAttachment(['path' => 'docs', 'name' => 'b', 'disk' => 'other']);

    $directories = Livewire::test(AttachmentBrowser::class)->viewData('directories');

    expect($directories)->toHaveCount(1)
        ->and($directories->first()->itemCount())->toBe(1);
});
