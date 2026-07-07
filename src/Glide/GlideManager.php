<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Glide;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use League\Glide\Responses\SymfonyResponseFactory;
use League\Glide\Server;
use League\Glide\ServerFactory;

class GlideManager
{
    /**
     * Memoized Glide server. Config is stable within a request, so the server —
     * and the Flysystem drivers it resolves — is built once and reused rather
     * than rebuilt on every server() call (imageIsSupported + resize + controller).
     */
    protected ?Server $server = null;

    /**
     * Build the Glide server, resolving source and cache disk names to Flysystem
     * adapters so remote disks (e.g. BunnyCDN) are read and written correctly.
     *
     * Passing a bare disk-name string causes Glide to treat it as a local path,
     * making imageIsSupported() always return false for remote-disk files.
     */
    public function server(): Server
    {
        if ($this->server !== null) {
            return $this->server;
        }

        $source = config('glide.source');

        if (is_string($source)) {
            $source = Storage::disk($source)->getDriver();
        }

        return $this->server = ServerFactory::create([
            'driver'              => $this->driver(),
            'source'              => $source,
            'cache'               => $this->cacheDisk()->getDriver(),
            'defaults'            => config('glide.defaults'),
            'presets'             => config('glide.presets'),
            'max_image_size'      => config('glide.max_image_size'),
            'response'            => new SymfonyResponseFactory(),
            'cache_path_callable' => function ($path, $params) {
                return app(OptionsParser::class)->toString($params) . '/' . $path;
            },
        ]);
    }

    public function driver(): string
    {
        return config('glide.driver', 'gd');
    }

    public function cacheDisk(): Filesystem
    {
        return is_string(config('glide.cache_disk'))
            ? Storage::disk(config('glide.cache_disk'))
            : Storage::build(config('glide.cache_disk'));
    }

    /**
     * @return array{files: int, size: int, readable_size: string}
     */
    public function cacheStats(): array
    {
        return Cache::remember('glide-cache-stats', now()->addMinutes(5), function () {
            $files = $this->cacheDisk()->allFiles();
            $size  = collect($files)->sum(fn ($file) => $this->cacheDisk()->size($file));

            return [
                'files'         => count($files),
                'size'          => $size,
                'readable_size' => $this->humanReadableSize($size),
            ];
        });
    }

    public function cacheFiles(): int
    {
        return $this->cacheStats()['files'];
    }

    public function cacheSize(): int
    {
        return $this->cacheStats()['size'];
    }

    public function cacheSizeHumanReadable(): string
    {
        return $this->cacheStats()['readable_size'];
    }

    public function humanReadableSize(int $bytes, $decimals = 2): string
    {
        $size   = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / (1024 ** $factor)) . ' ' . $size[$factor];
    }

    /**
     * Whether Glide can produce a variant for this path, decided from the
     * file extension against the driver's supported formats.
     *
     * Deliberately does NOT probe the file: the old makeImage() probe
     * generated a full-size variant on the cache disk (a remote write per
     * uncached item) just to answer a boolean. Files that carry a supported
     * extension but fail to decode are handled downstream — thumbnailUrl()
     * catches the resize failure and caches the original URL for a day.
     */
    public function imageIsSupported(string $path, array $params = []): bool
    {
        $extension = strtoupper(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === '') {
            return false;
        }

        // Extension spellings that drivers report under a different name:
        // gd_info() and Imagick::queryFormats() both say JPEG, never JPG.
        $canonical = ['JPG' => 'JPEG', 'TIF' => 'TIFF'][$extension] ?? $extension;
        $formats = $this->getSupportedImageFormats();

        return in_array($extension, $formats, true)
            || in_array($canonical, $formats, true);
    }

    /**
     * Retrieve supported image formats for the current driver.
     *
     * @param bool $onlyCommon Limit to the most common formats.
     */
    public function getSupportedImageFormats(bool $onlyCommon = true): array
    {
        $key = 'glide-supported-formats:' . $this->driver() . ':' . ($onlyCommon ? 'common' : 'all');

        return Cache::remember($key, now()->addDay(), function () use ($onlyCommon) {
            $commonFormats = ['AVIF','BMP','GIF','HEIC','HEIF','ICO','JPEG','JPG','PNG','SVG','TIFF','WEBP'];
            $driver = $this->driver();

            if ($driver === 'gd' && function_exists('gd_info')) {
                $formats = gd_info();
                $supported = collect();
                foreach ($formats as $key => $value) {
                    if ($value === false) {
                        continue;
                    }
                    if ($onlyCommon) {
                        foreach ($commonFormats as $format) {
                            if (str_contains($key, $format)) {
                                $supported->push($format);
                                break;
                            }
                        }
                    } else {
                        $format = strtoupper(str_replace([' ', 'Support'], '', $key));
                        $supported->push($format);
                    }
                }
                return $supported->unique()->values()->sort()->all();
            }

            if ($driver === 'imagick' && class_exists(\Imagick::class)) {
                $formats = \Imagick::queryFormats();
                return collect()
                    ->when($onlyCommon, fn ($c) => $c->merge($commonFormats)->filter(fn ($f) => in_array($f, $formats)))
                    ->when(!$onlyCommon, fn ($c) => $c->merge($formats))
                    ->unique()->values()->sort()->all();
            }

            return [];
        });
    }
}
