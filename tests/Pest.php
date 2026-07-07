<?php

use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;
use AwtTechnology\FilamentAttachmentLibrary\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(TestCase::class)->in('Feature');

/**
 * A minimal valid 1x1 pixel JPEG, base64-encoded. Writing this to the fake
 * disk (rather than only creating the DB row) means attachment/thumbnail
 * rendering can read real image bytes back off disk instead of failing with
 * a "file not found" error the moment anything inspects file dimensions.
 */
const TEST_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=';

function makeAttachment(array $attributes = []): Attachment
{
    $attachment = Attachment::create(array_merge([
        'name'      => 'photo-' . Str::random(6),
        'extension' => 'jpg',
        'disk'      => 'attachments',
        'mime_type' => 'image/jpeg',
        'path'      => null,
        'size'      => 1024,
    ], $attributes));

    Storage::disk($attachment->disk)->put(
        $attachment->full_path,
        base64_decode(TEST_JPEG_BASE64)
    );

    return $attachment;
}
