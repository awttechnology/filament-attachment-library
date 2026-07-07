<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentInfo;
use AwtTechnology\FilamentAttachmentLibrary\ViewModels\AttachmentViewModel;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

it('does not retrieve metadata when constructing the view model', function () {
    $attachment = makeAttachment(['name' => 'lazy']);
    $metadataKey = 'metadata-adapter-' . hash('sha256', $attachment->absolute_path);

    new AttachmentViewModel($attachment);

    // Constructing must not have populated the metadata cache — proof the
    // adapter (and its potential remote file read) never ran.
    expect(Cache::has($metadataKey))->toBeFalse();
});

it('loads metadata on demand via loadMetadata', function () {
    $attachment = makeAttachment(['name' => 'eager']);

    $viewModel = (new AttachmentViewModel($attachment))->loadMetadata();

    expect($viewModel->dimensions)->toBe('1x1')
        ->and($viewModel->bits)->not->toBeNull();
});

it('still shows dimensions in the info panel', function () {
    $attachment = makeAttachment(['name' => 'panel']);

    Livewire::test(AttachmentInfo::class)
        ->call('highlightAttachment', $attachment->id)
        ->assertSet('attachment.dimensions', '1x1');
});
