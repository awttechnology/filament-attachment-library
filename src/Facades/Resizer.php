<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin \AwtTechnology\FilamentAttachmentLibrary\Glide\Resizer
 */
class Resizer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'attachment.resizer';
    }
}
