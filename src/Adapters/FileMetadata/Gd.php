<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Adapters\FileMetadata;

use AwtTechnology\FilamentAttachmentLibrary\DataTransferObjects\FileMetadata;
use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

/**
 *  An adapter class for the PHP-GD extension.
 */
class Gd extends MetadataAdapter
{
    protected function retrieve(Attachment $file): FileMetadata|bool
    {
        $imageInfo = AttachmentManager::getImageSizes($file);
        if (! $imageInfo) {
            return false;
        }

        return new FileMetadata(
            width: $imageInfo[0],
            height: $imageInfo[1],
            bits: $imageInfo['bits'] ?? null,
            channels: $imageInfo['channels'] ?? null
        );
    }
}
