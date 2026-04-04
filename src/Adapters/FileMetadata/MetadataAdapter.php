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
            fn () => $this->retrieve($file)
        );
    }

    abstract protected function retrieve(Attachment $file): FileMetadata|bool;
}
