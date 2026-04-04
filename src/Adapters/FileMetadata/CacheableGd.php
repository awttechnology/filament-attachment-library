<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Adapters\FileMetadata;

use Illuminate\Support\Facades\Cache;
use AwtTechnology\FilamentAttachmentLibrary\Adapters\FileMetadata\Gd;
use AwtTechnology\FilamentAttachmentLibrary\DataTransferObjects\FileMetadata;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

class CacheableGd extends Gd
{
    /**
     * Override parent caching to store plain arrays instead of FileMetadata
     * readonly class instances, which PHP cannot deserialize from cache.
     */
    public function getMetadata(Attachment $file): FileMetadata|bool
    {
        $key    = implode('-', [$this->cacheKey, hash('sha256', $file->absolute_path)]);
        $cached = Cache::get($key);

        if ($cached === false) {
            return false;
        }

        if (is_array($cached)) {
            return new FileMetadata(...$cached);
        }

        $result = $this->retrieve($file);

        Cache::put($key, $result instanceof FileMetadata ? [
            'width'    => $result->width,
            'height'   => $result->height,
            'bits'     => $result->bits,
            'channels' => $result->channels,
        ] : false, now()->addDay());

        return $result;
    }
}
