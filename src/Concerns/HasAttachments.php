<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Config;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

trait HasAttachments
{
    public function attachments(): MorphToMany
    {
        return $this->morphToMany(Config::get('attachment-library.model', Attachment::class), 'attachable')->orderByPivot('order');
    }

    public function attachmentCollection(string $collection): MorphToMany
    {
        return $this->attachments()->wherePivot('collection', $collection);
    }
}
