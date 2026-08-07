<?php

use AwtTechnology\FilamentAttachmentLibrary\ViewModels\AttachmentViewModel;
use Illuminate\Support\Facades\Cache;

$cacheKey = fn ($attachment) => 'attachment-thumbnail-url:' . $attachment->id . ':h320';

it('emits the on-demand thumbnail route when the url is not cached', function () use ($cacheKey) {
    $attachment = makeAttachment();
    Cache::forget($cacheKey($attachment));

    $src = (new AttachmentViewModel($attachment))->thumbnailSrc();

    expect($src)->toBe(route('attachment.thumb', ['id' => $attachment->id]));
})->group('thumbnails');

it('emits the cached url directly when the thumbnail is warm', function () use ($cacheKey) {
    $attachment = makeAttachment();
    Cache::put($cacheKey($attachment), 'https://cdn.example.com/warm.jpg', now()->addDay());

    $src = (new AttachmentViewModel($attachment))->thumbnailSrc();

    expect($src)->toBe('https://cdn.example.com/warm.jpg');
})->group('thumbnails');

it('does not generate a thumbnail (no Glide server) while emitting the deferred src', function () use ($cacheKey) {
    $attachment = makeAttachment();
    Cache::forget($cacheKey($attachment));

    // Constructing the src for a cold thumbnail must never touch Glide — that
    // is precisely the work the route defers off the Livewire render.
    app()->forgetInstance('attachment.glide.manager');

    (new AttachmentViewModel($attachment))->thumbnailSrc();

    expect(app()->resolved('attachment.glide.manager'))->toBeFalse();
})->group('thumbnails');
