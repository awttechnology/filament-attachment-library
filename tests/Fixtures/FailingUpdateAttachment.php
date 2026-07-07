<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures;

use AwtTechnology\FilamentAttachmentLibrary\AttachmentQueryBuilder;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

/**
 * Attachment whose query builder throws on update(), used to force the mass
 * path-update in AttachmentManager::renameDirectory() to fail so the
 * rollback-on-failure behaviour can be exercised without a real DB outage.
 */
class FailingUpdateAttachment extends Attachment
{
    public function newEloquentBuilder($query): AttachmentQueryBuilder
    {
        return new class ($query) extends AttachmentQueryBuilder {
            public function update(array $values): int
            {
                throw new \RuntimeException('simulated bulk update failure');
            }
        };
    }
}
