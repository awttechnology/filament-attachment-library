<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Forms\Components;

use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * A Filament custom form field that fetches a remote file (image or PDF) and stores
 * it on a configured storage disk, then creates an Attachment record.
 *
 * Usage:
 *   RemoteFileFetcher::make('field_name')
 *       ->disk('public')           // Storage disk to save to (default: 'public')
 *       ->folder('uploads')        // Folder within the disk (created if missing)
 *       ->fileType('image')        // Restrict to 'image', 'pdf', or null for any
 *
 * The field renders a URL input and a filename input with a "Fetch File" button.
 * On success the field state is set to the stored path.
 */
class RemoteFileFetcher extends Field
{
    protected string $view = 'filament-attachment-library::forms.components.remote-file-fetcher';

    protected string|Closure $disk = 'public';

    protected string|Closure $folder = 'uploads';

    /**
     * Restrict accepted files to 'image', 'pdf', or null to allow any file type.
     */
    protected string|Closure|null $fileType = null;

    /**
     * @var array<string, string[]>
     */
    protected const ALLOWED_MIME_TYPES = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
        'pdf' => ['application/pdf'],
    ];

    /**
     * Set the file type restriction. Accepts 'image', 'pdf', or null (any).
     */
    public function fileType(string|Closure|null $fileType): static
    {
        $this->fileType = $fileType;

        return $this;
    }

    public function getFileType(): ?string
    {
        return $this->evaluate($this->fileType);
    }

    /**
     * Returns the allowed MIME types for the configured file type,
     * or null if no restriction is set.
     *
     * @return string[]|null
     */
    protected function getAllowedMimeTypes(): ?array
    {
        return match ($this->getFileType()) {
            'image' => self::ALLOWED_MIME_TYPES['image'],
            'pdf' => self::ALLOWED_MIME_TYPES['pdf'],
            default => null,
        };
    }

    /**
     * Set the storage disk to save fetched files to.
     */
    public function disk(string|Closure $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    public function getDisk(): string
    {
        return $this->evaluate($this->disk);
    }

    /**
     * Set the folder within the disk where files will be stored.
     * The folder is created automatically if it does not exist.
     */
    public function folder(string|Closure $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    public function getFolder(): string
    {
        return $this->evaluate($this->folder);
    }

    /**
     * Fetch a remote file and store it on the configured disk.
     *
     * Validates the URL, checks the Content-Type against the configured file type
     * restriction, corrects the filename extension if it does not match the remote
     * file, and creates an Attachment record on success.
     *
     * Returns an array with:
     *   - success (bool)
     *   - path (string)           — on success
     *   - attachment_id (int)     — on success
     *   - error (string)          — on failure
     *
     * @return array{success: bool, path?: string, attachment_id?: int, error?: string}
     */
    #[ExposedLivewireMethod]
    public function fetchFile(string $url, string $filename): array
    {
        if (blank($url)) {
            return ['success' => false, 'error' => 'URL is required.'];
        }

        if (blank($filename)) {
            return ['success' => false, 'error' => 'Filename is required.'];
        }

        if (Validator::make(['url' => $url], ['url' => 'url'])->fails()) {
            return ['success' => false, 'error' => 'The URL is not valid.'];
        }

        // Validate URL points to an image or PDF via Content-Type header
        try {
            $head = Http::timeout(10)->head($url);
        } catch (ConnectionException $e) {
            return ['success' => false, 'error' => 'Could not connect to the URL: '.$e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Unexpected error checking URL: '.$e->getMessage()];
        }

        if ($head->failed()) {
            return ['success' => false, 'error' => "Could not reach the URL (HTTP {$head->status()}). Please check it is correct."];
        }

        $contentType = $head->header('Content-Type');
        $allowedTypes = $this->getAllowedMimeTypes();

        if ($allowedTypes !== null) {
            $isAllowed = collect($allowedTypes)->contains(fn (string $type): bool => str_starts_with($contentType, $type));

            if (! $isAllowed) {
                $expected = match ($this->getFileType()) {
                    'image' => 'an image',
                    'pdf' => 'a PDF',
                    default => 'an allowed file type',
                };

                return ['success' => false, 'error' => "URL does not point to {$expected} (Content-Type: {$contentType})."];
            }
        }

        // Derive the correct extension from the remote URL or mime type
        $remoteExtension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        $mimeToExtension = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'application/pdf' => 'pdf',
        ];

        $correctExtension = filled($remoteExtension)
            ? $remoteExtension
            : ($mimeToExtension[strtolower(Str::before($contentType, ';'))] ?? null);

        if (filled($correctExtension)) {
            $providedExtension = strtolower(Str::afterLast($filename, '.'));

            if ($providedExtension !== $correctExtension) {
                $filename = Str::beforeLast($filename, '.').'.'.$correctExtension;
            }
        }

        $disk = $this->getDisk();
        $folder = $this->getFolder();

        // Ensure the folder exists, create it if not
        if (! Storage::disk($disk)->directoryExists($folder)) {
            Storage::disk($disk)->makeDirectory($folder);
        }

        $path = $folder.'/'.$filename;

        // Check file already exists
        if (Storage::disk($disk)->exists($path)) {
            return ['success' => false, 'error' => "File '{$filename}' already exists in '{$folder}'."];
        }

        // Fetch and store
        try {
            $response = Http::timeout(60)->get($url);
        } catch (ConnectionException $e) {
            return ['success' => false, 'error' => 'Connection failed while fetching the file: '.$e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Unexpected error fetching the file: '.$e->getMessage()];
        }

        if ($response->failed()) {
            return ['success' => false, 'error' => "Failed to fetch the remote file (HTTP {$response->status()})."];
        }

        $body = $response->body();

        Storage::disk($disk)->put($path, $body);

        $attachment = Attachment::create([
            'name' => Str::beforeLast($filename, '.'),
            'extension' => Str::afterLast($filename, '.'),
            'disk' => $disk,
            'mime_type' => $contentType,
            'path' => $path,
            'size' => strlen($body),
        ]);

        return ['success' => true, 'path' => $path, 'attachment_id' => $attachment->id];
    }
}
