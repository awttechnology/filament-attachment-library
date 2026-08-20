<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Forms\Components;

use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * AttachmentField + an interactive Croppie cropper.
 *
 * Keeps the full attachment-library experience (browse the library / upload /
 * remote-fetch, state stored as an attachment id, storeAsUrl(), SyncsAttachables)
 * and adds a "Crop" step: the currently-selected image is loaded into a Croppie
 * modal (via the same-origin `attachment` proxy route to avoid a tainted-canvas
 * SecurityError), and on save the cropped result is written to the field's
 * disk/directory as a NEW Attachment (see StoreCroppedAttachment). The original
 * attachment is preserved; the field's selection swaps to the cropped derivative.
 *
 * Single-select only (the base field's single-value semantics are inherited).
 */
class CroppieAttachmentField extends AttachmentField
{
    protected string $view = 'filament-attachment-library::forms.components.croppie-attachment-field';

    protected string|Closure|null $disk = null;

    protected string|Closure|null $imageName = null;

    protected string|Closure|null $viewportType = null;

    protected string|int|Closure|null $viewportWidth = null;

    protected string|int|Closure|null $viewportHeight = null;

    protected string|int|Closure|null $boundaryWidth = null;

    protected string|int|Closure|null $boundaryHeight = null;

    protected bool|Closure|null $enableResize = null;

    protected bool|Closure|null $enableZoom = null;

    protected bool|Closure|null $enableOrientation = false;

    protected bool|Closure|null $showZoomer = null;

    protected bool|Closure|string $mouseWheelZoom = true;

    protected bool|Closure|null $forceCircleResult = false;

    protected string|Closure|null $imageFormat = null;

    protected string|Closure|null $imageSize = null;

    protected string|Closure|null $modalSize = null;

    protected string|Closure|null $modalTitle = null;

    protected string|Closure|null $modalDescription = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Cropper produces images; constrain the library picker to images too.
        $this->image();
    }

    /**
     * Disk the cropped derivative is written to. Defaults to the library's
     * configured disk (attachment-library.disk), so it matches where the source
     * attachment lives (e.g. bunny).
     */
    public function disk(string|Closure|null $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    public function getDiskName(): string
    {
        return $this->evaluate($this->disk) ?? Config::get('attachment-library.disk', 'public');
    }

    /**
     * Optional base filename (without extension) for the cropped derivative. When
     * unset, StoreCroppedAttachment derives "{source-name}-cropped-{token}".
     */
    public function imageName(string|Closure|null $imageName): static
    {
        $this->imageName = $imageName;

        return $this;
    }

    public function getImageName(): ?string
    {
        return $this->evaluate($this->imageName);
    }

    public function viewportType(string|Closure|null $viewportType): static
    {
        $this->viewportType = $viewportType;

        return $this;
    }

    public function getViewportType(): string
    {
        return $this->evaluate($this->viewportType)
            ?? Config::get('attachment-library.croppie.viewport_type', 'square');
    }

    public function viewportWidth(string|int|Closure|null $viewportWidth): static
    {
        $this->viewportWidth = $viewportWidth;

        return $this;
    }

    public function getViewportWidth(): int
    {
        return (int) ($this->evaluate($this->viewportWidth)
            ?? Config::get('attachment-library.croppie.viewport_width', 200));
    }

    public function viewportHeight(string|int|Closure|null $viewportHeight): static
    {
        $this->viewportHeight = $viewportHeight;

        return $this;
    }

    public function getViewportHeight(): int
    {
        return (int) ($this->evaluate($this->viewportHeight)
            ?? Config::get('attachment-library.croppie.viewport_height', 200));
    }

    public function boundaryWidth(string|int|Closure|null $boundaryWidth): static
    {
        $this->boundaryWidth = $boundaryWidth;

        return $this;
    }

    public function getBoundaryWidth(): int
    {
        return (int) ($this->evaluate($this->boundaryWidth)
            ?? Config::get('attachment-library.croppie.boundary_width', 600));
    }

    public function boundaryHeight(string|int|Closure|null $boundaryHeight): static
    {
        $this->boundaryHeight = $boundaryHeight;

        return $this;
    }

    public function getBoundaryHeight(): int
    {
        return (int) ($this->evaluate($this->boundaryHeight)
            ?? Config::get('attachment-library.croppie.boundary_height', 400));
    }

    public function enableResize(bool|Closure|null $enableResize = true): static
    {
        $this->enableResize = $enableResize;

        return $this;
    }

    public function getEnableResize(): bool
    {
        return (bool) ($this->evaluate($this->enableResize)
            ?? Config::get('attachment-library.croppie.enable_resize', true));
    }

    public function enableZoom(bool|Closure|null $enableZoom = true): static
    {
        $this->enableZoom = $enableZoom;

        return $this;
    }

    public function getEnableZoom(): bool
    {
        return (bool) ($this->evaluate($this->enableZoom)
            ?? Config::get('attachment-library.croppie.enable_zoom', true));
    }

    public function enableOrientation(bool|Closure|null $enableOrientation = true): static
    {
        $this->enableOrientation = $enableOrientation;

        return $this;
    }

    public function getEnableOrientation(): bool
    {
        return (bool) $this->evaluate($this->enableOrientation);
    }

    public function showZoomer(bool|Closure|null $showZoomer = true): static
    {
        $this->showZoomer = $showZoomer;

        return $this;
    }

    public function getShowZoomer(): bool
    {
        return (bool) ($this->evaluate($this->showZoomer)
            ?? Config::get('attachment-library.croppie.show_zoomer', true));
    }

    public function mouseWheelZoom(bool|Closure|string $mouseWheelZoom = true): static
    {
        $this->mouseWheelZoom = $mouseWheelZoom;

        return $this;
    }

    public function getMouseWheelZoom(): bool|string
    {
        return $this->evaluate($this->mouseWheelZoom);
    }

    public function forceCircleResult(bool|Closure|null $forceCircleResult = true): static
    {
        $this->forceCircleResult = $forceCircleResult;

        return $this;
    }

    public function getForceCircleResult(): bool
    {
        return (bool) $this->evaluate($this->forceCircleResult);
    }

    public function imageFormat(string|Closure|null $imageFormat): static
    {
        $this->imageFormat = $imageFormat;

        return $this;
    }

    public function getImageFormat(): string
    {
        return $this->evaluate($this->imageFormat)
            ?? Config::get('attachment-library.croppie.image_format', 'png');
    }

    public function imageSize(string|Closure|null $imageSize): static
    {
        $this->imageSize = $imageSize;

        return $this;
    }

    public function getImageSize(): string
    {
        return $this->evaluate($this->imageSize)
            ?? Config::get('attachment-library.croppie.image_size', 'viewport');
    }

    public function modalSize(string|Closure|null $modalSize): static
    {
        $this->modalSize = $modalSize;

        return $this;
    }

    public function getModalSize(): string
    {
        return $this->evaluate($this->modalSize)
            ?? Config::get('attachment-library.croppie.modal_size', '4xl');
    }

    public function modalTitle(string|Closure|null $modalTitle): static
    {
        $this->modalTitle = $modalTitle;

        return $this;
    }

    public function getModalTitle(): string
    {
        return $this->evaluate($this->modalTitle)
            ?? __('filament-attachment-library::views.field.crop.title');
    }

    public function modalDescription(string|Closure|null $modalDescription): static
    {
        $this->modalDescription = $modalDescription;

        return $this;
    }

    public function getModalDescription(): ?string
    {
        return $this->evaluate($this->modalDescription)
            ?? __('filament-attachment-library::views.field.crop.description');
    }

    /**
     * Same-origin URL for the currently-selected image, used as the Croppie
     * source. Routes through the `attachment` proxy (AttachmentController), which
     * streams remote-disk bytes locally so the browser canvas is not tainted by a
     * cross-origin CDN image. Returns null when nothing (image) is selected.
     */
    public function getCropSourceUrl(): ?string
    {
        $id = $this->getState();
        $id = $id instanceof Collection ? $id->first() : $id;

        if (blank($id)) {
            return null;
        }

        $attachment = Attachment::find($id);

        if (! $attachment || ! $attachment->isImage()) {
            return null;
        }

        return route('attachment', ['attachment' => $attachment->full_path]);
    }
}
