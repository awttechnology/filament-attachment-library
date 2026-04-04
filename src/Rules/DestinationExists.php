<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use AwtTechnology\FilamentAttachmentLibrary\DataTransferObjects\Filename;
use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

class DestinationExists implements ValidationRule
{
    public function __construct(private ?string $path, private ?int $attachmentId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $path = "{$this->path}/";

        if ($value instanceof UploadedFile) {
            $path .= new Filename($value);
        }

        if (is_string($value) && $this->attachmentId !== null) {
            /** @var Attachment $attachment */
            $attachment = Attachment::find($this->attachmentId);

            $path .= implode('.', array_filter([$value, $attachment->extension]));
        }

        if (is_string($value) && $this->attachmentId === null) {
            $path .= $value;
        }

        if (AttachmentManager::destinationExists($path)) {
            $fail('filament-attachment-library::validation.destination_exists')->translate();
        }
    }
}
