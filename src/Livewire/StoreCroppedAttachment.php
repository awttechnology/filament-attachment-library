<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Livewire;

use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Receives a base64 cropped image from the CroppieAttachmentField blade, writes
 * it to the field's disk/directory as a NEW Attachment (via
 * AttachmentManager::putContents()), and dispatches the new attachment id back to
 * the field so its selection swaps to the cropped derivative.
 *
 * One instance per field (embedded via @livewire in the field blade), scoped by
 * the md5(statePath) hash so a page with several crop fields never crosses wires.
 */
class StoreCroppedAttachment extends Component
{
    public string $statePath;

    public function mount(string $statePath = ''): void
    {
        // Store the hashed statePath — the blade dispatches events keyed by md5.
        $this->statePath = md5($statePath);
    }

    #[On('upload-cropped-attachment')]
    public function store(
        string $base64Image,
        string $statePath,
        string $disk,
        ?string $directory = null,
        string $imageFormat = 'png',
        int|string|null $sourceAttachmentId = null,
        ?string $imageName = null,
    ): void {
        // Only the field this component belongs to should act on the event.
        if ($statePath !== $this->statePath) {
            return;
        }

        // Strip the data URI prefix Croppie emits (data:image/png;base64,....).
        $base64Image = preg_replace('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', '', $base64Image);
        $contents = base64_decode($base64Image, true);

        if ($contents === false || $contents === '') {
            return;
        }

        $name = $imageName ?: $this->derivativeName($sourceAttachmentId);

        $attachment = AttachmentManager::setDisk($disk)->putContents(
            contents: $contents,
            mimeType: "image/{$imageFormat}",
            directory: $directory,
            name: $name,
            extension: $imageFormat,
        );

        $this->dispatch(
            'set-cropped-attachment-'.$this->statePath,
            attachmentId: $attachment->id,
        );
    }

    /**
     * "{source-name}-cropped-{token}" — unique so nothing is overwritten and the
     * original stays intact. Falls back to a bare "cropped-{token}" base when the
     * source cannot be resolved.
     */
    protected function derivativeName(int|string|null $sourceAttachmentId): string
    {
        $token = Str::lower(Str::random(8));

        $source = filled($sourceAttachmentId) ? Attachment::find($sourceAttachmentId) : null;

        return $source
            ? "{$source->name}-cropped-{$token}"
            : "cropped-{$token}";
    }

    public function render(): View
    {
        return view('filament-attachment-library::livewire.store-cropped-attachment');
    }
}
