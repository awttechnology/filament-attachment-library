<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Http\Controllers;

use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Http\Middleware\EnsureRenderableAttachment;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Symfony\Component\HttpFoundation\Response;

class AttachmentController implements HasMiddleware
{
    public function __invoke(Request $request, Attachment $attachment): Response
    {
        // Remote disks (e.g. BunnyCDN) have no local path for response()->file(),
        // so stream their bytes through this same-origin route. This is what lets
        // a browser canvas (e.g. the Croppie cropper) read a CDN-hosted image
        // without a cross-origin tainted-canvas SecurityError.
        if (AttachmentManager::isRemote($attachment)) {
            $contents = AttachmentManager::getContents($attachment);
            abort_if($contents === null, Response::HTTP_NOT_FOUND);

            return response($contents, Response::HTTP_OK, [
                'Content-Type' => $attachment->mime_type,
                'Content-Length' => (string) strlen($contents),
            ]);
        }

        return response()->file($attachment->absolute_path);
    }

    public static function middleware(): array
    {
        return [EnsureRenderableAttachment::class];
    }
}
