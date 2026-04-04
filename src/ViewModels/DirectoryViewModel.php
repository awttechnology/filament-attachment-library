<?php

namespace AwtTechnology\FilamentAttachmentLibrary\ViewModels;

use AwtTechnology\FilamentAttachmentLibrary\DataTransferObjects\Directory;

class DirectoryViewModel
{
    public Directory $directory;

    public string $name;

    public string $fullPath;

    protected int $itemCount;

    public function __construct(Directory $directory)
    {
        $this->directory = $directory;
        $this->name = $directory->name;
        $this->fullPath = $directory->fullPath;
    }

    public function itemCount(): int
    {
        $class = config('attachment-library.class_mapping.attachment');

        return $this->itemCount ??= $class::where('path', $this->fullPath)->count();
    }
}
