<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;
use AwtTechnology\FilamentAttachmentLibrary\ViewModels\AttachmentViewModel;

/**
 * On-demand thumbnail resolver.
 *
 * The browser grid emits this route (instead of the final URL) for any thumbnail
 * whose URL is not yet cached, so the Livewire render never blocks on Glide
 * generation. This request generates + caches the derivative, then redirects to
 * the real URL. The next render finds the cache warm and emits the direct URL,
 * so this controller is only hit while a folder is cold.
 */
class ThumbnailController
{
    public function __invoke(Request $request, int $id): RedirectResponse
    {
        // Resolve by id explicitly: Attachment::resolveRouteBinding() looks up by
        // filename, so implicit model binding would 404 on a numeric id.
        $attachment = Attachment::findOrFail($id);

        $url = (new AttachmentViewModel($attachment))->thumbnailUrl();

        abort_if($url === null, 404);

        return redirect()->away($url);
    }
}
