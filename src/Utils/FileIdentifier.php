<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Utils;

class FileIdentifier
{
    public function __construct(
        public string $disk,
        public ?string $path,
        public string $filename,
        public string $extension,
    ) {
    }

    public function __toString(): string
    {
        return "{$this->disk}::{$this->path}/{$this->filename}.{$this->extension}";
    }
}
