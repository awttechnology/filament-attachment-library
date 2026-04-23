<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Glide;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use AwtTechnology\FilamentAttachmentLibrary\Enums\Fit;
use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Facades\Glide;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

/**
 * Generates image URLs based on Glide parameters.
 *
 * For remote cache disks (e.g. BunnyCDN) it writes the resized variant via
 * makeImage() and returns the public CDN URL directly, bypassing the
 * GlideController entirely so images are served from the edge on every request.
 *
 * For local cache disks it falls back to signed GlideController route URLs.
 */
class Resizer
{
    private ?Attachment $attachment = null;

    public ?string $path = null;
    public ?int $width = null;
    public ?int $height = null;
    public ?string $format = 'jpg';
    public ?string $size = 'full';
    public ?float $aspectRatio = null;
    public Fit $fit = Fit::CROP;

    private array $crop = [];

    public function __construct(public array $sizes)
    {
    }

    public function src(string|int|Attachment $src): static
    {
        if (is_int($src)) {
            $src = Attachment::find($src);
        }

        if ($src instanceof Attachment) {
            $this->attachment = $src;
            $x = $src->focal_point['x'] ?? 50;
            $y = $src->focal_point['y'] ?? 50;
            $this->crop($x, $y);
        }

        $this->path = $this->getPath($src);

        return $this;
    }

    public function path(?string $path): static
    {
        $this->path = $path;
        return $this;
    }

    public function width(int $width): static
    {
        $this->width = $width;
        return $this;
    }

    public function height(int $height): static
    {
        $this->height = $height;
        return $this;
    }

    public function fit(Fit $fit): static
    {
        $this->fit = $fit;
        return $this;
    }

    public function getFit(): string
    {
        if ($this->fit === Fit::CROP && !empty($this->crop)) {
            $x    = $this->crop['x']    ?? 50;
            $y    = $this->crop['y']    ?? 50;
            $zoom = $this->crop['zoom'] ?? 1;
            return "crop-{$x}-{$y}-{$zoom}";
        }
        return $this->fit->value;
    }

    public function crop(int $x = 50, int $y = 50, float $zoom = 1): static
    {
        $this->crop = ['x' => $x, 'y' => $y, 'zoom' => $zoom];
        return $this->fit(Fit::CROP);
    }

    public function calculateWidth(): ?float
    {
        if ($this->width) {
            return round($this->width * $this->getSizeRatio());
        }
        if ($this->height && $this->aspectRatio) {
            return round($this->calculateHeight() * $this->aspectRatio);
        }
        if ($this->height) {
            [$width, $height] = $this->getImageSize();
            return !empty($height) ? round($this->calculateHeight() / $height * $width) : 0;
        }
        return null;
    }

    public function calculateHeight(): ?float
    {
        if ($this->height) {
            return round($this->height * $this->getSizeRatio());
        }
        if ($this->width && $this->aspectRatio) {
            return round(($this->calculateWidth() / $this->aspectRatio));
        }
        if ($this->width) {
            [$width, $height] = $this->getImageSize();
            return !empty($width) ? round($this->calculateWidth() / $width * $height) : 0;
        }
        return null;
    }

    public function size(string $size): static
    {
        $this->size = $size;
        return $this;
    }

    public function getSizeRatio(): float
    {
        return $this->sizes[$this->size] ?? 1;
    }

    public function getImageSize(): ?array
    {
        $file = $this->attachment ?? AttachmentManager::file($this->path);
        if (!$file || !$file->isImage()) {
            return null;
        }
        [$width, $height] = AttachmentManager::getImageSizes($file);
        return [$width, $height];
    }

    public function aspectRatio(string|float|null $aspectRatio): static
    {
        if (is_string($aspectRatio)) {
            $parts = explode('/', $aspectRatio);
            $this->aspectRatio = intval($parts[0]) / intval($parts[1]);
        } else {
            $this->aspectRatio = $aspectRatio;
        }
        return $this;
    }

    public function format(string $format): static
    {
        $this->format = $format;
        return $this;
    }

    protected function getPath(string|Attachment $src): ?string
    {
        if ($src instanceof Attachment) {
            return $src->full_path;
        }
        return $src;
    }

    public function cacheKey(): string
    {
        return sha1($this->path . $this->width . $this->height . $this->format . $this->size . $this->aspectRatio);
    }

    /**
     * Resize the image and return an array with the URL, width, and height.
     *
     * Callers must ensure the path is Glide-supported before calling (e.g. via
     * Glide::imageIsSupported()) — this method no longer guards internally.
     *
     * When the configured cache disk is a remote disk (e.g. bunny-glide), the
     * resized variant is written via makeImage() and the public CDN URL is
     * returned directly so the browser loads it from the edge without PHP.
     *
     * For local cache disks a signed GlideController route URL is returned.
     */
    public function resize(): array
    {
        $width  = $this->calculateWidth();
        $height = $this->calculateHeight();

        $params = array_filter([
            'w'   => $width ?: null,
            'h'   => $height ?: null,
            'fit' => $this->getFit(),
            'fm'  => $this->format,
        ]);

        $cacheDisk = config('glide.cache_disk');
        $isRemoteCache = is_string($cacheDisk)
            && config("filesystems.disks.{$cacheDisk}.driver", 'local') !== 'local';

        if ($isRemoteCache) {
            // makeImage() is cache-first: writes to remote disk on first call,
            // returns the cache path (without root prefix) on every call.
            $cachePath = Glide::server()->makeImage($this->path, $params);
            $url = Storage::disk($cacheDisk)->url($cachePath);
        } else {
            $url = URL::signedRoute('glide', [
                'options' => app(OptionsParser::class)->toString([
                    'w'   => $width,
                    'h'   => $height,
                    'fit' => $this->getFit(),
                    'fm'  => $this->format,
                ]),
                'path' => $this->path,
            ]);
        }

        return [
            'width'  => $width,
            'height' => $height,
            'url'    => $url,
        ];
    }

    /**
     * Compute the Glide cache path for a given set of parameters without writing
     * anything. Useful for pre-warming or checking cache existence.
     */
    public function getCachePath(?int $width = null, ?int $height = null, ?string $format = null): string
    {
        $params = array_filter([
            'w'   => $width,
            'h'   => $height,
            'fit' => $this->getFit(),
            'fm'  => $format ?? $this->format,
        ]);

        return app(OptionsParser::class)->toString($params) . '/' . $this->path;
    }
}
