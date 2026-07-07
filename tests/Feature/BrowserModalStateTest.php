<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use Livewire\Livewire;

it('opens the modal without a multiple flag without crashing', function () {
    Livewire::test(AttachmentBrowser::class)
        ->call('openModal', 'some.path', 5, null, null, null, null)
        ->assertSet('multiple', false)
        ->assertSet('selected', [5]);
});

it('clears the previous selection when reopening for another field', function () {
    Livewire::test(AttachmentBrowser::class)
        ->call('openModal', 'field.a', 5, false)
        ->call('closeModal', false)
        ->call('openModal', 'field.b', null, false)
        ->assertSet('selected', []);
});

it('preserves basePath and browsing state across close', function () {
    Livewire::test(AttachmentBrowser::class, ['basePath' => 'media'])
        ->call('openModal', 'field.a', null, false, null, false, 'images')
        ->call('closeModal', false)
        ->assertSet('basePath', 'media');
});

it('resets pagination when the modal closes', function () {
    collect(range(1, 30))->each(fn ($i) => makeAttachment(['name' => 'img-' . $i]));

    Livewire::test(AttachmentBrowser::class)
        ->set('search', 'img')
        ->call('gotoPage', 2)
        ->call('closeModal', false)
        ->assertSet('paginators.page', 1);
});

it('dispatches the selected id as a scalar on save-close for single select', function () {
    $attachment = makeAttachment();

    Livewire::test(AttachmentBrowser::class)
        ->call('openModal', 'data.featured_image_id', null, false)
        ->call('selectAttachment', $attachment->id)
        ->call('closeModal', true)
        ->assertDispatched(
            'attachments-selected-' . md5('data.featured_image_id'),
            statePath: 'data.featured_image_id',
            selected: $attachment->id,
        );
});
