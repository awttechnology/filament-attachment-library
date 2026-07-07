<?php

use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;
use AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures\TestAttachmentManager;
use Illuminate\Contracts\Filesystem\Filesystem;
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
    expect($attachment->path)->toBe('src')->and($attachment->isDirty())->toBeFalse();
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
    expect($attachment->name)->toBe('a')->and($attachment->isDirty())->toBeFalse();
});

it('throws and leaves the database untouched when the filesystem move returns false during move', function () {
    $attachment = makeAttachment(['path' => 'src', 'name' => 'a']);

    $fs = Mockery::mock(Filesystem::class);
    $fs->shouldReceive('exists')->andReturn(false);
    $fs->shouldReceive('move')->once()->andReturn(false);

    $manager = new TestAttachmentManager($fs);

    expect(fn () => $manager->move($attachment, 'dst'))->toThrow(RuntimeException::class);

    expect($attachment->fresh()->path)->toBe('src');
});

it('throws and leaves the database untouched when the filesystem move returns false during rename', function () {
    $attachment = makeAttachment(['path' => 'src', 'name' => 'a']);

    $fs = Mockery::mock(Filesystem::class);
    $fs->shouldReceive('exists')->andReturn(false);
    $fs->shouldReceive('move')->once()->andReturn(false);

    $manager = new TestAttachmentManager($fs);

    expect(fn () => $manager->rename($attachment, 'b'))->toThrow(RuntimeException::class);

    expect($attachment->fresh()->name)->toBe('a');
});
