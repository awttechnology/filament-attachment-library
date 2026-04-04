<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Actions\Traits;

trait HasCurrentPath
{
    public ?string $currentPath = null;

    public function setCurrentPath(?string $path): static
    {
        $this->currentPath = $path;

        return $this;
    }
}
