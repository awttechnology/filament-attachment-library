<?php

use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
 * EditAttachmentAction already re-dispatches 'highlight-attachment' after
 * saving so AttachmentInfo rebuilds its view model from a fresh model load
 * (see EditAttachmentAction::setUp(), the $this->action() closure). Move and
 * Replace only dispatched 'refresh-attachments', so the highlighted info
 * panel kept showing the pre-move/replace path and URL indefinitely.
 *
 * These tests exercise the real, unmocked action classes mounted on
 * AttachmentBrowser (the same component that registers them in production)
 * and assert on the observable contract: the dispatched browser event that
 * AttachmentInfo listens for to rebuild its panel. Driving a second, fully
 * wired AttachmentInfo component to receive that dispatch and re-render is
 * already covered by ViewModelWireableTest's panel-refresh coverage of
 * AttachmentInfo::highlightAttachment(); asserting the dispatch here proves
 * the fixed actions now emit the same signal EditAttachmentAction does,
 * without duplicating that coverage.
 */

it('dispatches highlight-attachment after moving an attachment so the info panel refreshes', function () {
    $attachment = makeAttachment(['path' => 'src', 'name' => 'a']);
    AttachmentManager::createDirectory('dst');

    Livewire::test(AttachmentBrowser::class)
        ->callAction('moveAttachment', data: ['path' => 'dst'], arguments: ['attachment_id' => $attachment->id])
        ->assertDispatched('highlight-attachment', $attachment->id);
});

it('dispatches highlight-attachment after replacing an attachment so the info panel refreshes', function () {
    $attachment = makeAttachment(['path' => 'docs', 'name' => 'report', 'extension' => 'pdf', 'mime_type' => 'application/pdf']);

    Livewire::test(AttachmentBrowser::class)
        ->callAction('replaceAttachment', data: [
            'file' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf'),
        ], arguments: ['attachment_id' => $attachment->id])
        ->assertDispatched('highlight-attachment', $attachment->id);
});
