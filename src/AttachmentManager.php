<?php

namespace AwtTechnology\FilamentAttachmentLibrary;

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
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
     * @param  string|null  $path  Use `null` for root of disk.
     */
    public function directories(?string $path = null): Collection
    {
        if (Config::get('attachment-library.directory_source', 'filesystem') === 'database') {
            $directories = $this->directoriesFromDatabase($path);

            // Database mode derives folders from attachment rows, so folders that
            // exist on the storage disk but hold no (synced) files are invisible.
            // Merge in the disk's real subfolders for the current path so they can
            // be navigated into and used as upload targets.
            if (Config::get('attachment-library.merge_storage_directories', true)) {
                $directories = $this->mergeStorageDirectories($directories, $path);
            }

            return $directories;
        }

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

    private function directoriesFromDatabase(?string $path = null): Collection
    {
        $hidden = Config::get('attachment-library.hidden_directories', []);

        $prefix = $path === null ? null : $path.'/';

        // Only fetch paths under the requested prefix. The LIKE narrows rows at the
        // DB (LIKE wildcards in folder names may over-match, so the precise
        // str_starts_with filter below remains the exact gate).
        $allPaths = $this->attachmentClass::whereDisk($this->disk)
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->when($prefix !== null, fn ($query) => $query->where('path', 'LIKE', $prefix.'%'))
            ->distinct()
            ->pluck('path');

        if ($path === null) {
            $directoryPaths = $allPaths
                ->map(fn ($p) => explode('/', $p)[0])
                ->unique()
                ->values();
        } else {
            $directoryPaths = $allPaths
                ->filter(fn ($p) => str_starts_with($p, $prefix))
                ->map(fn ($p) => $path.'/'.explode('/', substr($p, strlen($prefix)))[0])
                ->unique()
                ->values();
        }

        return $directoryPaths
            ->map(fn ($dir) => new $this->directoryClass($dir))
            ->reject(fn (Directory $directory) => in_array($directory->name, $hidden, true))
            ->values();
    }

    /**
     * Union the storage disk's real subfolders for $path into a DB-derived
     * directory set. One non-recursive list call; folders already present in the
     * DB set are deduped by fullPath, hidden directories are rejected, and a
     * failed storage listing degrades to the DB-only set.
     *
     * @param  Collection<int, Directory>  $directories
     * @return Collection<int, Directory>
     */
    protected function mergeStorageDirectories(Collection $directories, ?string $path): Collection
    {
        $hidden = Config::get('attachment-library.hidden_directories', []);
        $existing = $directories->map(fn (Directory $directory) => $directory->fullPath)->all();

        try {
            $storage = collect($this->getFilesystem()->directories($path));
        } catch (\Throwable $exception) {
            // A Bunny/storage hiccup must not break the browser — keep the
            // DB-derived folders and surface nothing extra this render.
            report($exception);

            return $directories;
        }

        return $directories->concat(
            $storage
                ->map(fn ($directory) => new $this->directoryClass($directory))
                ->reject(fn (Directory $directory) => in_array($directory->name, $hidden, true))
                ->reject(fn (Directory $directory) => in_array($directory->fullPath, $existing, true))
        )->values();
    }

    protected function syncIfDue(?string $directory): void
    {
        $ttl = Config::get('attachment-library.auto_sync_interval', 300);
        $cacheKey = 'attachment-library-last-sync:'.$this->disk.':'.($directory ?? '');

        // Cache::add is atomic: exactly one concurrent request wins the gate and
        // syncs; the rest skip. Claiming before the work (not after) closes the
        // window where several requests all saw a missing key and synced at once.
        if (! Cache::add($cacheKey, true, $ttl)) {
            return;
        }

        try {
            $this->updateFiles($directory);
        } catch (\Throwable $exception) {
            Cache::forget($cacheKey);
            throw $exception;
        }
    }

    public function updateFiles(?string $directory): void
    {
        $files = $this->getFilesystem()->files($directory);

        /** @var Collection<string, bool> $existing */
        $existing = $this->attachmentClass::whereDisk($this->disk)
            ->wherePath($directory)
            ->select(['name', 'extension'])
            ->get()
            ->mapWithKeys(fn ($item) => ["{$item->name}.{$item->extension}" => true]);

        $data = [];
        foreach ($files as $file) {
            $filename = new Filename($file);
            if (! $filename->name || ! $filename->extension) {
                continue;
            }
            if ($existing->has("{$filename->name}.{$filename->extension}")) {
                continue;
            }
            $data[] = [
                'name' => $filename->name,
                'extension' => $filename->extension,
                'mime_type' => $this->getFilesystem()->mimeType($file),
                'disk' => $this->disk,
                'path' => $filename->path,
                'size' => $this->getFilesystem()->size($file),
            ];
        }
        if (! empty($data)) {
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
            throw new DestinationAlreadyExistsException;
        }
        $disk->put($path, $file->getContent());

        return $this->attachmentClass::create([
            'name' => $filename->name,
            'extension' => $filename->extension,
            'mime_type' => $file->getMimeType(),
            'disk' => $this->disk,
            'path' => $desiredPath,
            'size' => $file->getSize(),
        ]);
    }

    /**
     * Persist raw file contents (e.g. a client-side cropped image decoded from
     * base64) and register the matching Attachment record — the raw-bytes sibling
     * of upload(), which requires an UploadedFile. Honours the manager's current
     * disk (set via setDisk()). The caller owns the (already-unique) $name; a UUID
     * is used when none is supplied. Returns the created Attachment.
     */
    public function putContents(
        string $contents,
        string $mimeType,
        ?string $directory = null,
        ?string $name = null,
        ?string $extension = 'png',
    ): Attachment {
        $name ??= (string) Str::uuid();
        $filename = "{$name}.{$extension}";
        $this->validateBasename($filename);
        $path = implode('/', array_filter([$directory, $filename]));
        if ($this->getFilesystem()->put($path, $contents) === false) {
            throw new \RuntimeException("Failed to write file to {$path}.");
        }

        return $this->attachmentClass::create([
            'name' => $name,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'disk' => $this->disk,
            'path' => $directory,
            'size' => strlen($contents),
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
        $oldPath = $attachment->full_path;
        if ($disk->exists($path) && $path !== $oldPath) {
            throw new DestinationAlreadyExistsException;
        }
        // Write the replacement before deleting the original so a failed write
        // never loses the existing file. A same-named replacement overwrites in
        // place and needs no delete at all.
        // FilesystemAdapter::put() returns false (rather than throwing) on
        // failure unless the disk has 'throw' => true, so a false return must be
        // treated as a failed write before any delete of the original can happen.
        if ($disk->put($path, $file->getContent()) === false) {
            throw new \RuntimeException("Failed to write replacement file to {$path}.");
        }
        if ($path !== $oldPath) {
            $disk->delete($oldPath);
        }
        // Clear caches keyed on the old file (dimensions/metadata/thumbnail) before
        // the update so a same-named replacement does not serve stale values.
        $attachment->forgetCaches();
        $attachment->update([
            'name' => $filename->name,
            'extension' => $filename->extension,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        return $attachment;
    }

    /** @throws DisallowedCharacterException */
    public function validateBasename(string $name): void
    {
        if (preg_match_all($this->allowedCharacters, $name)) {
            throw new DisallowedCharacterException;
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
            throw new DestinationAlreadyExistsException;
        }
        $originalPath = $file->full_path;
        // FilesystemAdapter::move() returns false (rather than throwing) on
        // failure unless the disk has 'throw' => true, so a false return must be
        // treated as a failed move before the database is touched.
        if ($disk->move($originalPath, $path) === false) {
            throw new \RuntimeException("Failed to move file to {$path}.");
        }
        try {
            $file->update(['name' => $name]);
        } catch (\Throwable $exception) {
            // Database update failed: move the file back so filesystem and
            // database stay consistent, then rethrow.
            $disk->move($path, $originalPath);
            // update() fills before saving, so a failed save leaves the model
            // dirty with the rolled-back value — discard it to match the DB.
            $file->refresh();
            throw $exception;
        }
    }

    /** @throws DestinationAlreadyExistsException */
    public function move(Attachment $file, ?string $desiredPath): void
    {
        $disk = $this->getFilesystem();
        $path = "{$desiredPath}/{$file->filename}";
        if ($disk->exists($path)) {
            throw new DestinationAlreadyExistsException;
        }
        $originalPath = $file->full_path;
        // FilesystemAdapter::move() returns false (rather than throwing) on
        // failure unless the disk has 'throw' => true, so a false return must be
        // treated as a failed move before the database is touched.
        if ($disk->move($originalPath, $path) === false) {
            throw new \RuntimeException("Failed to move file to {$path}.");
        }
        try {
            $file->update(['path' => $desiredPath]);
        } catch (\Throwable $exception) {
            $disk->move($path, $originalPath);
            // update() fills before saving, so a failed save leaves the model
            // dirty with the rolled-back value — discard it to match the DB.
            $file->refresh();
            throw $exception;
        }
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
            throw new DestinationAlreadyExistsException;
        }
        // FilesystemAdapter::move() returns false (rather than throwing) on
        // failure unless the disk has 'throw' => true, so a false return must be
        // treated as a failed move before any database row is touched.
        if ($disk->move($currentPath, $newPath) === false) {
            throw new \RuntimeException("Failed to move directory to {$newPath}.");
        }

        // Rewrite the path prefix for every affected row in a single UPDATE rather
        // than loading and saving each attachment individually. Mass updates skip
        // model events, so the id-keyed thumbnail cache is invalidated explicitly
        // (ids survive the rename; the cached URL would otherwise point at the old
        // path). Ids are captured before the update, since whereInPath no longer
        // matches afterwards.
        //
        // The whole capture-and-update sequence is guarded: if the mass update
        // fails partway (or outright), the directory move already happened on
        // disk, so it is rolled back to keep the filesystem and database
        // consistent before the exception propagates.
        try {
            $ids = $this->attachmentClass::whereDisk($this->disk)->whereInPath($currentPath)->pluck('id');

            // Prefix-safe: keep everything after the old prefix and prepend the new
            // one, instead of REPLACE() which substitutes the substring anywhere in
            // the path (corrupting e.g. products/x/products).
            $connection = $this->attachmentClass::query()->getConnection();
            $quotedNewPath = $connection->getPdo()->quote($newPath);
            $remainder = 'SUBSTR(path, '.(mb_strlen($currentPath, 'UTF-8') + 1).')';
            $expression = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
                ? "CONCAT({$quotedNewPath}, {$remainder})"
                : "{$quotedNewPath} || {$remainder}";

            $this->attachmentClass::whereDisk($this->disk)
                ->whereInPath($currentPath)
                ->update(['path' => $connection->raw($expression)]);
        } catch (\Throwable $exception) {
            $disk->move($newPath, $currentPath);
            throw $exception;
        }

        foreach ($ids as $id) {
            Cache::forget('attachment-thumbnail-url:'.$id.':h320');
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
            throw new DestinationAlreadyExistsException;
        }
        $flags = array_unique([DirectoryStrategies::CREATE_PARENT_DIRECTORIES, ...$flags]);
        $createParentDirectoriesFlag = in_array(DirectoryStrategies::CREATE_PARENT_DIRECTORIES, $flags);
        $hasParentDirectory = $disk->exists(dirname($path));
        if (! $createParentDirectoriesFlag && ! $hasParentDirectory) {
            throw new NoParentDirectoryException;
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
        if (! config()->has("filesystems.disks.{$file->disk}")) {
            return false;
        }

        return config("filesystems.disks.{$file->disk}.driver", 'local') !== 'local';
    }

    public function isType(Attachment $file, string $type): bool
    {
        return $this->mimeTypeIsType($file->mime_type, $type);
    }

    /**
     * Mime-mapping check that needs no Attachment model — lets view models
     * rehydrated from a Livewire payload answer isImage()/isVideo() without
     * a database load.
     */
    public function mimeTypeIsType(?string $mimeType, string $type): bool
    {
        return in_array($mimeType, $this->attachmentTypeMapping[$type] ?? [], true);
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

            return (new $metadataRetriever)->getMetadata($file);
        }

        return false;
    }

    public function getImageSizes(Attachment|string $file): ?array
    {
        if (is_string($file)) {
            $file = $this->file($file);
        }
        if (! $file || ! $file->isImage()) {
            return null;
        }

        return Cache::remember(
            implode('-', ['attachment-manager', hash('sha256', $file->full_path)]),
            now()->addDay(),
            function () use ($file) {
                $path = $file->absolute_path;
                if ($file->isRemote()) {
                    $contents = $file->getContents();
                    if (! $contents) {
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
