<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures;

use AwtTechnology\FilamentAttachmentLibrary\AttachmentManager;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * AttachmentManager with an injectable filesystem so tests can simulate
 * filesystem failures (a Storage fake cannot be made to throw).
 */
class TestAttachmentManager extends AttachmentManager
{
    public function __construct(private readonly Filesystem $filesystem)
    {
        parent::__construct();
    }

    protected function getFilesystem(): Filesystem
    {
        return $this->filesystem;
    }
}
