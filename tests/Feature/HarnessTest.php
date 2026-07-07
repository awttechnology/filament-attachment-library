<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use Livewire\Livewire;

it('boots the package and creates attachments', function () {
    $attachment = makeAttachment(['name' => 'hero']);

    expect($attachment->filename)->toBe('hero.jpg')
        ->and($attachment->disk)->toBe('attachments');
});

it('renders the attachment browser', function () {
    makeAttachment(['name' => 'browser-smoke']);

    Livewire::test(AttachmentBrowser::class)
        ->assertHasNoErrors()
        ->assertSee('browser-smoke');
});
