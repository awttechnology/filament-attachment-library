<?php

use AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures\TestAttachmentManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;

it('keeps the original file when writing the replacement fails', function () {
    $attachment = makeAttachment(['path' => 'docs', 'name' => 'report', 'extension' => 'pdf', 'mime_type' => 'application/pdf']);

    $fs = Mockery::mock(Filesystem::class);
    $fs->shouldReceive('exists')->andReturn(false);
    $fs->shouldReceive('put')->once()->andThrow(new RuntimeException('write failed'));
    $fs->shouldNotReceive('delete');

    $manager = new TestAttachmentManager($fs);
    $upload = UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf');

    expect(fn () => $manager->replace($upload, $attachment))->toThrow(RuntimeException::class);
});

it('writes the replacement before deleting the original', function () {
    $attachment = makeAttachment(['path' => 'docs', 'name' => 'report', 'extension' => 'pdf', 'mime_type' => 'application/pdf']);

    $fs = Mockery::mock(Filesystem::class);
    $fs->shouldReceive('exists')->andReturn(false);
    $fs->shouldReceive('put')->once()->ordered()->andReturn(true);
    $fs->shouldReceive('delete')->once()->ordered()->andReturn(true);

    $manager = new TestAttachmentManager($fs);
    $upload = UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf');

    $manager->replace($upload, $attachment);

    expect($attachment->fresh()->name)->toBe('replacement');
});
