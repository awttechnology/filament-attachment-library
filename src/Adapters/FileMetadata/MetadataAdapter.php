<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Adapters\FileMetadata;

use Illuminate\Support\Facades\Cache;
use AwtTechnology\FilamentAttachmentLibrary\DataTransferObjects\FileMetadata;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

abstract class MetadataAdapter
{
    protected string $cacheKey = 'metadata-adapter';

    public function getMetadata(Attachment $file): FileMetadata|bool
    {
        $path = $file->absolute_path;

        return Cache::remember(
            implode('-', [$this->cacheKey, hash('sha256', $path)]),
            now()->addDay(),
            function () use ($file) {
                try {
                    return $this->retrieve($file);
                } catch (\Throwable $exception) {
                    // Cache the failure as false so a missing/corrupt file is
                    // reported once per TTL, not re-read on every render.
                    // forgetCaches() clears this key when the file is replaced.
                    report($exception);
                    return false;
                }
            }
        );
    }

    abstract protected function retrieve(Attachment $file): FileMetadata|bool;
}
