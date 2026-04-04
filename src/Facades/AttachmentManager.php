<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin \AwtTechnology\FilamentAttachmentLibrary\AttachmentManager
 */
class AttachmentManager extends Facade
{
    /**
     * Return the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'attachment.manager';
    }
}
