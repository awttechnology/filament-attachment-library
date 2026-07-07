<?php

use AwtTechnology\FilamentAttachmentLibrary\Facades\Glide;
use AwtTechnology\FilamentAttachmentLibrary\Facades\Resizer;
use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use AwtTechnology\FilamentAttachmentLibrary\ViewModels\AttachmentViewModel;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('falls back to the original url when thumbnail generation throws', function () {
    $attachment = makeAttachment();

    Glide::shouldReceive('imageIsSupported')->andReturn(true);
    Resizer::shouldReceive('src')->andThrow(new RuntimeException('corrupt image'));

    $viewModel = new AttachmentViewModel($attachment);

    expect($viewModel->thumbnailUrl())->toBe($attachment->url);
});

it('renders the browser even when an image file is missing from disk', function () {
    $attachment = makeAttachment(['name' => 'ghost']);
    Storage::disk('attachments')->delete($attachment->full_path);

    Livewire::test(AttachmentBrowser::class)
        ->assertHasNoErrors()
        ->assertSee('ghost');
});
