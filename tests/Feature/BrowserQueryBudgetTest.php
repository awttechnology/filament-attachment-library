<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use AwtTechnology\FilamentAttachmentLibrary\Facades\Glide;
use AwtTechnology\FilamentAttachmentLibrary\ViewModels\AttachmentViewModel;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('renders a full page of attachments within the query budget', function () {
    foreach (range(1, 30) as $i) {
        makeAttachment(['name' => "img-{$i}"]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    Livewire::test(AttachmentBrowser::class)->assertHasNoErrors();

    // Phase 2 exit criterion (spec): a small constant per page regardless of
    // page size. Phase 1 baseline was 60.
    expect($queries)->toBeLessThanOrEqual(10);
});

it('never touches the Glide server when thumbnails are warm', function () {
    $attachments = collect(range(1, 5))->map(fn ($i) => makeAttachment(['name' => "warm-{$i}"]));

    // Warm every thumbnail key the page will read.
    foreach ($attachments as $attachment) {
        cache()->put(
            'attachment-thumbnail-url:' . $attachment->id . ':h320',
            'https://cdn.example.com/warm.jpg',
            now()->addDay()
        );
    }

    // Real GlideManager logic runs, but building the Glide server (the
    // gateway to every filesystem/CDN read or write) is forbidden.
    Glide::partialMock()->shouldNotReceive('server');

    Livewire::test(AttachmentBrowser::class)->assertHasNoErrors();
});
