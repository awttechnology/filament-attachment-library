<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Symfony\Component\HttpFoundation\Response;
use AwtTechnology\FilamentAttachmentLibrary\Http\Middleware\EnsureRenderableAttachment;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

class AttachmentController implements HasMiddleware
{
    public function __invoke(Request $request, Attachment $attachment): Response
    {
        return response()->file($attachment->absolute_path);
    }

    public static function middleware(): array
    {
        return [EnsureRenderableAttachment::class];
    }
}
