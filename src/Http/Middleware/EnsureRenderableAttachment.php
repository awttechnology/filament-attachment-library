<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use AwtTechnology\FilamentAttachmentLibrary\Enums\AttachmentType;

/**
 * Prevent non-previewable attachments from being rendered such as executables.
 */
class EnsureRenderableAttachment
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AttachmentType::isRenderable($request->attachment->type)) {
            abort(Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }
}
