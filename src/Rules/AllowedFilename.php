<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use AwtTechnology\FilamentAttachmentLibrary\DataTransferObjects\Filename;
use AwtTechnology\FilamentAttachmentLibrary\Exceptions\DisallowedCharacterException;
use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;

class AllowedFilename implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $filename = $value;

        if ($value instanceof UploadedFile) {
            $filename = new Filename($value);
        }

        try {
            AttachmentManager::validateBasename($filename);
        } catch (DisallowedCharacterException) {
            $fail('filament-attachment-library::validation.allowed_filename')->translate();
        }
    }
}
