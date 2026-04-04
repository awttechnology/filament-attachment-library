<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin \AwtTechnology\FilamentAttachmentLibrary\FilamentAttachmentLibrary
 */
class FilamentAttachmentLibrary extends Facade
{
    /**
     * Return the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \AwtTechnology\FilamentAttachmentLibrary\FilamentAttachmentLibrary::class;
    }
}
