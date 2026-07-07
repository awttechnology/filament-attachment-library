<?php

use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;
use Illuminate\Support\Facades\Storage;

it('restores the file when the database update fails during move', function () {
    Storage::disk('attachments')->put('src/a.jpg', 'contents');
    $attachment = makeAttachment(['path' => 'src', 'name' => 'a']);

    Attachment::updating(function () {
        throw new RuntimeException('db down');
    });

    expect(fn () => AttachmentManager::move($attachment, 'dst'))
        ->toThrow(RuntimeException::class);

    Storage::disk('attachments')->assertExists('src/a.jpg');
    Storage::disk('attachments')->assertMissing('dst/a.jpg');
    expect($attachment->fresh()->path)->toBe('src');
});

it('restores the file when the database update fails during rename', function () {
    Storage::disk('attachments')->put('src/a.jpg', 'contents');
    $attachment = makeAttachment(['path' => 'src', 'name' => 'a']);

    Attachment::updating(function () {
        throw new RuntimeException('db down');
    });

    expect(fn () => AttachmentManager::rename($attachment, 'b'))
        ->toThrow(RuntimeException::class);

    Storage::disk('attachments')->assertExists('src/a.jpg');
    Storage::disk('attachments')->assertMissing('src/b.jpg');
    expect($attachment->fresh()->name)->toBe('a');
});
