<?php

use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentInfo;
use AwtTechnology\FilamentAttachmentLibrary\ViewModels\AttachmentViewModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('rehydrates from its payload without querying the database', function () {
    $attachment = makeAttachment(['name' => 'wired', 'path' => 'docs']);
    $payload = (new AttachmentViewModel($attachment))->loadMetadata()->toLivewire();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $rebuilt = AttachmentViewModel::fromLivewire($payload);

    expect($queries)->toBe(0)
        ->and($rebuilt->id)->toBe($attachment->id)
        ->and($rebuilt->name)->toBe('wired')
        ->and($rebuilt->url)->toBe($attachment->url)
        ->and($rebuilt->mimeType)->toBe('image/jpeg')
        ->and($rebuilt->dimensions)->toBe('1x1')
        ->and($rebuilt->isImage())->toBeTrue()
        ->and($rebuilt->isVideo())->toBeFalse()
        ->and($rebuilt->isSelected([$attachment->id]))->toBeTrue()
        ->and($rebuilt->createdAt?->equalTo($attachment->created_at))->toBeTrue();
});

it('still hydrates legacy id-only payloads', function () {
    $attachment = makeAttachment(['name' => 'legacy']);

    $rebuilt = AttachmentViewModel::fromLivewire(['id' => $attachment->id]);

    expect($rebuilt->name)->toBe('legacy');
});

it('does not query the attachments table when re-rendering the info panel', function () {
    $attachment = makeAttachment(['name' => 'panel-rerender']);

    $component = Livewire::test(AttachmentInfo::class)
        ->call('highlightAttachment', $attachment->id);

    $attachmentQueries = 0;
    DB::listen(function ($query) use (&$attachmentQueries) {
        if (str_contains($query->sql, 'attachments')) {
            $attachmentQueries++;
        }
    });

    $component->call('$refresh');

    expect($attachmentQueries)->toBe(0);
});

it('lazily loads the model when a caller needs it', function () {
    $attachment = makeAttachment(['name' => 'lazy-model']);
    $rebuilt = AttachmentViewModel::fromLivewire(
        (new AttachmentViewModel($attachment))->toLivewire()
    );

    expect($rebuilt->model()->getKey())->toBe($attachment->getKey());
});

it('sources the thumbnail fallback URL from the model rather than a stale payload on a cache miss', function () {
    // Non-image mime so the unsupported-format branch of thumbnailUrl()
    // returns deterministically without touching Glide/Resizer.
    $attachment = makeAttachment(['path' => 'old', 'name' => 'doc', 'extension' => 'pdf', 'mime_type' => 'application/pdf']);

    $payload = (new AttachmentViewModel($attachment))->toLivewire();
    $staleUrl = $payload['url'];

    // Move the file after the payload was captured: the row/file now live at
    // a different path, so a fresh load resolves a different URL than the
    // one baked into the old payload.
    AttachmentManager::move($attachment, 'new');

    // The 'updated' model event already invalidates this key; forget it
    // explicitly too so the test exercises a genuine cache miss regardless.
    Cache::forget('attachment-thumbnail-url:' . $attachment->id . ':h320');

    $rehydrated = AttachmentViewModel::fromLivewire($payload);

    $thumbnailUrl = $rehydrated->thumbnailUrl();

    expect($thumbnailUrl)
        ->not->toBe($staleUrl)
        ->toBe($attachment->fresh()->url);
});
