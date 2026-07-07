<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use Livewire\Livewire;

beforeEach(function () {
    makeAttachment(['name' => 'hero']);
    makeAttachment(['name' => 'my-hero']);
});

it('matches by prefix by default so the name index is usable', function () {
    Livewire::test(AttachmentBrowser::class)
        ->set('search', 'hero')
        ->assertSee('hero')
        ->assertDontSee('my-hero');
});

it('matches anywhere when search_mode is contains', function () {
    config()->set('filament-attachment-library.search_mode', 'contains');

    Livewire::test(AttachmentBrowser::class)
        ->set('search', 'hero')
        ->assertSee('my-hero');
});
