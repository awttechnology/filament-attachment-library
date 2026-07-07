<?php

use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;
use AwtTechnology\FilamentAttachmentLibrary\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(TestCase::class)->in('Feature');

/**
 * A minimal valid 1x1 pixel JPEG. Returns the decoded bytes so
 * attachment/thumbnail rendering can read real image bytes back off disk
 * instead of failing with a "file not found" error.
 */
function testJpegBytes(): string
{
    static $bytes = null;

    if ($bytes === null) {
        $base64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=';
        $bytes = base64_decode($base64);
    }

    return $bytes;
}

function makeAttachment(array $attributes = []): Attachment
{
    $bytes = testJpegBytes();

    $attachment = Attachment::create(array_merge([
        'name'      => 'photo-' . Str::random(6),
        'extension' => 'jpg',
        'disk'      => 'attachments',
        'mime_type' => 'image/jpeg',
        'path'      => null,
        'size'      => strlen($bytes),
    ], $attributes));

    // Only store file on disk if it's a configured disk (skip 'other' which is DB-only for tests)
    if ($attachment->disk !== 'other') {
        Storage::disk($attachment->disk)->put(
            $attachment->full_path,
            $bytes
        );
    }

    return $attachment;
}
