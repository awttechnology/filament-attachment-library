<?php

namespace AwtTechnology\FilamentAttachmentLibrary;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use AwtTechnology\FilamentAttachmentLibrary\Adapters\FileMetadata\MetadataAdapter;
use AwtTechnology\FilamentAttachmentLibrary\DataTransferObjects\Directory;
use AwtTechnology\FilamentAttachmentLibrary\DataTransferObjects\FileMetadata;
use AwtTechnology\FilamentAttachmentLibrary\DataTransferObjects\Filename;
use AwtTechnology\FilamentAttachmentLibrary\Enums\DirectoryStrategies;
use AwtTechnology\FilamentAttachmentLibrary\Exceptions\DestinationAlreadyExistsException;
use AwtTechnology\FilamentAttachmentLibrary\Exceptions\DisallowedCharacterException;
use AwtTechnology\FilamentAttachmentLibrary\Exceptions\IncompatibleClassMappingException;
use AwtTechnology\FilamentAttachmentLibrary\Exceptions\NoParentDirectoryException;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

/**
 * Performs attachment related actions on database and filesystem.
 */
class AttachmentManager
{
    protected string $disk;
    protected string $attachmentClass;
    protected string $directoryClass;
    protected array $metadataRetrievers;
    protected string $allowedCharacters;
    protected array $attachmentTypeMapping;

    /** @throws IncompatibleClassMappingException */
    public function __construct()
    {
        $this->disk = Config::get('attachment-library.disk', 'public');
        $this->attachmentClass = Config::get('attachment-library.class_mapping.attachment', Attachment::class);
        $this->directoryClass = Config::get('attachment-library.class_mapping.directory', Directory::class);
        $this->attachmentTypeMapping = Config::get('attachment-library.attachment_mime_type_mapping', []);
        $this->allowedCharacters = Config::get('attachment-library.allowed_characters', '/[^\\pL\\pN_\.\- ]+/u');
        $this->metadataRetrievers = Config::get('attachment-library.metadata_retrievers', []);
        $this->ensureCompatibleClasses();
    }

    /** @throws IncompatibleClassMappingException */
    protected function ensureCompatibleClasses(): void
    {
        if (! is_a($this->attachmentClass, Attachment::class, true)) {
            throw new IncompatibleClassMappingException($this->attachmentClass, Attachment::class);
        }
        if (! is_a($this->directoryClass, Directory::class, true)) {
            throw new IncompatibleClassMappingException($this->directoryClass, Directory::class);
        }
    }

    public function setDisk(string $disk): AttachmentManager
    {
        $this->disk = $disk;
        return $this;
    }

    /**
     * Return all directories under a given path, excluding any listed in
     * attachment-library.hidden_directories.
     *
     * @param string|null $path Use `null` for root of disk.
     */
    public function directories(?string $path = null): Collection
    {
        if (Config::get('attachment-library.auto_sync', true)) {
            $this->syncIfDue($path);
        }

        $hidden = Config::get('attachment-library.hidden_directories', []);

        return collect($this->getFilesystem()->directories($path))
            ->each(function ($directory) {
                if (Config::get('attachment-library.auto_sync', true)) {
                    $this->syncIfDue($directory);
                }
            })
            ->map(fn ($directory) => new $this->directoryClass($directory))
            ->reject(fn (Directory $directory) => in_array($directory->name, $hidden, true));
    }

    protected function syncIfDue(?string $directory): void
    {
        $ttl = Config::get('attachment-library.auto_sync_interval', 300);
        $cacheKey = 'attachment-library-last-sync:' . $this->disk . ':' . ($directory ?? '');
        if (Cache::has($cacheKey)) {
            return;
        }
        $this->updateFiles($directory);
        Cache::put($cacheKey, true, $ttl);
    }

    public function updateFiles(?string $directory): void
    {
        $files = $this->getFilesystem()->files($directory);

        /** @var Collection<string, boolean> $existing */
        $existing = $this->attachmentClass::whereDisk($this->disk)
            ->wherePath($directory)
            ->select(['name', 'extension'])
            ->get()
            ->mapWithKeys(fn ($item) => ["{$item->name}.{$item->extension}" => true]);

        $data = [];
        foreach ($files as $file) {
            $filename = new Filename($file);
            if (!$filename->name || !$filename->extension) {
                continue;
            }
            if ($existing->has("{$filename->name}.{$filename->extension}")) {
                continue;
            }
            $data[] = [
                'name'      => $filename->name,
                'extension' => $filename->extension,
                'mime_type' => $this->getFilesystem()->mimeType($file),
                'disk'      => $this->disk,
                'path'      => $filename->path,
                'size'      => $this->getFilesystem()->size($file),
            ];
        }
        if (!empty($data)) {
            $this->attachmentClass::insert($data);
        }
    }

    protected function getFilesystem(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /** @param string|null $path Use `null` for root of disk. */
    public function files(?string $path = null): Collection
    {
        return $this->attachmentClass::whereDisk($this->disk)->wherePath($path)->get();
    }

    public function file(string $path): ?Attachment
    {
        return $this->attachmentClass::whereDisk($this->disk)->whereFilename(new Filename($path))->first();
    }

    public function find(string|int $id): ?Attachment
    {
        return $this->attachmentClass::whereDisk($this->disk)->find($id);
    }

    public function findByUrl(string $url): ?Attachment
    {
        $placeholder = 'PLACEHOLDER';
        $baseUrlWithPlaceholder = route('attachment', ['attachment' => $placeholder]);
        if (Str::endsWith($baseUrlWithPlaceholder, $placeholder)) {
            $baseUrl = substr($baseUrlWithPlaceholder, 0, -strlen($placeholder));
        } else {
            $baseUrl = $baseUrlWithPlaceholder;
        }
        if (Str::startsWith($url, $baseUrl)) {
            $path = substr($url, strlen($baseUrl));
        } else {
            $path = $url;
        }
        return $this->file($path);
    }

    /**
     * @throws DestinationAlreadyExistsException
     * @throws DisallowedCharacterException
     */
    public function upload(UploadedFile $file, ?string $desiredPath = null): Attachment
    {
        $filename = new Filename($file);
        if (is_null($filename->extension)) {
            throw new DisallowedCharacterException('File must have an extension.');
        }
        $this->validateBasename($filename);
        $path = implode('/', array_filter([$desiredPath, $filename]));
        $disk = $this->getFilesystem();
        if ($disk->exists($path)) {
            throw new DestinationAlreadyExistsException();
        }
        $disk->put($path, $file->getContent());
        return $this->attachmentClass::create([
            'name'      => $filename->name,
            'extension' => $filename->extension,
            'mime_type' => $file->getMimeType(),
            'disk'      => $this->disk,
            'path'      => $desiredPath,
            'size'      => $file->getSize(),
        ]);
    }

    /**
     * @throws DestinationAlreadyExistsException
     * @throws DisallowedCharacterException
     */
    public function replace(UploadedFile $file, Attachment $attachment): Attachment
    {
        $filename = new Filename($file);
        $this->validateBasename($filename);
        $disk = $this->getFilesystem();
        $path = implode('/', array_filter([$attachment->path, $filename]));
        if ($disk->exists($path) && $path !== $attachment->full_path) {
            throw new DestinationAlreadyExistsException();
        }
        $disk->delete($attachment->full_path);
        $disk->put($path, $file->getContent());
        $attachment->update([
            'name'      => $filename->name,
            'extension' => $filename->extension,
            'mime_type' => $file->getMimeType(),
            'size'      => $file->getSize(),
        ]);
        return $attachment;
    }

    /** @throws DisallowedCharacterException */
    public function validateBasename(string $name): void
    {
        if (preg_match_all($this->allowedCharacters, $name)) {
            throw new DisallowedCharacterException();
        }
    }

    /**
     * @throws DestinationAlreadyExistsException
     * @throws DisallowedCharacterException
     */
    public function rename(Attachment $file, string $name): void
    {
        $this->validateBasename($name);
        $disk = $this->getFilesystem();
        $path = "{$file->path}/{$name}.{$file->extension}";
        if ($disk->exists($path)) {
            throw new DestinationAlreadyExistsException();
        }
        $disk->move($file->full_path, $path);
        $file->update(['name' => $name]);
        $file->save();
    }

    /** @throws DestinationAlreadyExistsException */
    public function move(Attachment $file, ?string $desiredPath): void
    {
        $disk = $this->getFilesystem();
        $path = "{$desiredPath}/{$file->filename}";
        if ($disk->exists($path)) {
            throw new DestinationAlreadyExistsException();
        }
        $disk->move($file->full_path, $path);
        $file->update(['path' => $desiredPath]);
        $file->save();
    }

    /**
     * @throws DestinationAlreadyExistsException
     * @throws DisallowedCharacterException
     */
    public function renameDirectory(string $currentPath, string $newName): Directory
    {
        $this->validateBasename($newName);
        $newPath = explode('/', $currentPath);
        $newPath[array_key_last($newPath)] = $newName;
        $newPath = implode('/', $newPath);
        $disk = $this->getFilesystem();
        if ($disk->exists($newPath)) {
            throw new DestinationAlreadyExistsException();
        }
        $disk->move($currentPath, $newPath);
        $attachments = $this->attachmentClass::whereDisk($this->disk)->whereInPath($currentPath)->get();
        foreach ($attachments as $attachment) {
            $attachment->update(['path' => str_replace($currentPath, $newPath, $attachment->path)]);
        }
        return new Directory($newPath);
    }

    /**
     * Always passes CREATE_PARENT_DIRECTORIES so object storage (e.g. BunnyCDN)
     * works correctly — the root/intermediate paths are virtual prefixes, not real
     * objects, so the parent-existence check would always fail otherwise.
     *
     * @throws DestinationAlreadyExistsException
     * @throws DisallowedCharacterException
     * @throws NoParentDirectoryException
     */
    public function createDirectory(string $path, DirectoryStrategies ...$flags): Directory
    {
        $this->validatePath($path);
        $disk = $this->getFilesystem();
        if ($disk->exists($path)) {
            throw new DestinationAlreadyExistsException();
        }
        $flags = array_unique([DirectoryStrategies::CREATE_PARENT_DIRECTORIES, ...$flags]);
        $createParentDirectoriesFlag = in_array(DirectoryStrategies::CREATE_PARENT_DIRECTORIES, $flags);
        $hasParentDirectory = $disk->exists(dirname($path));
        if (! $createParentDirectoriesFlag && ! $hasParentDirectory) {
            throw new NoParentDirectoryException();
        }
        $disk->makeDirectory($path);
        return new Directory($path);
    }

    /** @throws DisallowedCharacterException */
    protected function validatePath(string $path): void
    {
        foreach (explode('/', $path) as $directory) {
            $this->validateBasename($directory);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $this->attachmentClass::whereDisk($this->disk)->whereInPath($path)->delete();
        $this->getFilesystem()->deleteDirectory($path);
    }

    public function delete(Attachment $file): void
    {
        $this->getFilesystem()->delete($file->full_path);
        $file->delete();
    }

    public function destinationExists(string $path): bool
    {
        return $this->getFilesystem()->exists($path);
    }

    /**
     * Return the public URL for an attachment.
     * For remote disks (e.g. BunnyCDN) returns the CDN URL directly so files are
     * served from the edge rather than proxied through the local AttachmentController.
     */
    public function getUrl(Attachment $file): string|bool
    {
        if ($this->isRemote($file)) {
            return Storage::disk($file->disk)->url($file->full_path);
        }

        return route('attachment', ['attachment' => $file->full_path]);
    }

    public function getAbsolutePath(Attachment $file): string
    {
        return Storage::disk($file->disk)->path($file->full_path);
    }

    public function getContents(Attachment $file): ?string
    {
        return Storage::disk($file->disk)->get($file->full_path);
    }

    public function isRemote(Attachment $file): bool
    {
        if (!config()->has("filesystems.disks.{$file->disk}")) {
            return false;
        }
        return config("filesystems.disks.{$file->disk}.driver", 'local') !== 'local';
    }

    public function isType(Attachment $file, string $type): bool
    {
        return in_array($file->mime_type, $this->attachmentTypeMapping[$type] ?? []);
    }

    public function getType(Attachment $file): ?string
    {
        foreach ($this->attachmentTypeMapping as $key => $value) {
            if (in_array($file->mime_type, $value)) {
                return $key;
            }
        }
        return null;
    }

    /** @throws IncompatibleClassMappingException */
    public function getMetadata(Attachment $file): FileMetadata|false
    {
        foreach ($this->metadataRetrievers as $metadataRetriever => $mimeTypes) {
            $matchesMime = array_filter($mimeTypes, fn ($mimeType) => fnmatch($mimeType, $file->mime_type));
            if (empty($matchesMime)) {
                continue;
            }
            if (! is_a($metadataRetriever, MetadataAdapter::class, true)) {
                throw new IncompatibleClassMappingException($metadataRetriever, MetadataAdapter::class);
            }
            return (new $metadataRetriever())->getMetadata($file);
        }
        return false;
    }

    public function getImageSizes(Attachment|string $file): ?array
    {
        if (is_string($file)) {
            $file = $this->file($file);
        }
        if (!$file || !$file->isImage()) {
            return null;
        }
        return Cache::remember(
            implode('-', ['attachment-manager', hash('sha256', $file->full_path)]),
            now()->addDay(),
            function () use ($file) {
                $path = $file->absolute_path;
                if ($file->isRemote()) {
                    $contents = $file->getContents();
                    if (!$contents) {
                        return null;
                    }
                    $tmpFile = tmpfile();
                    $metaData = stream_get_meta_data($tmpFile);
                    fwrite($tmpFile, $contents);
                    $path = $metaData['uri'];
                }
                return getimagesize($path) ?: null;
            }
        );
    }
}
