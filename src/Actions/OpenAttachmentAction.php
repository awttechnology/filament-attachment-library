<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Actions;

use Filament\Actions\Action;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

class OpenAttachmentAction extends Action
{
    protected function setUp(): void
    {
        $this->color('gray');

        $this->url(function (array $arguments) {
            /** @var Attachment $attachment */
            $attachment = Attachment::find($arguments['attachment_id']);

            return $attachment->url;
        });

        $this->openUrlInNewTab();
    }
}
