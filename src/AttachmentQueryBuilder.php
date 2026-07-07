<?php

namespace AwtTechnology\FilamentAttachmentLibrary;

use Illuminate\Database\Eloquent\Builder;
use AwtTechnology\FilamentAttachmentLibrary\DataTransferObjects\Filename;

/**
 * @template TModelClass of \Illuminate\Database\Eloquent\Model
 * @extends Builder<TModelClass>
 */
class AttachmentQueryBuilder extends Builder
{
    /**
     * Filter files by disk.
     */
    public function whereDisk(string $disk): static
    {
        return $this->where('disk', $disk);
    }

    /**
     * Filter files by exact path.
     */
    public function wherePath(?string $path): static
    {
        return $this->where('path', $path);
    }

    /**
     * Filter all files in path including in subdirectories.
     */
    public function whereInPath(string $path): static
    {
        return $this->where(function (Builder $query) use ($path) {
            $query->where('path', '=', $path)
                ->orWhere('path', 'LIKE', "{$path}/%");
        });
    }

    /**
     * Filter files by reversing a public URL back to its path/name/extension columns.
     * Avoids full-table fetches when looking up an attachment by URL.
     */
    public function whereUrl(string $url): static
    {
        $urlPath = ltrim(parse_url($url, PHP_URL_PATH), '/');
        $folder  = pathinfo($urlPath, PATHINFO_DIRNAME);
        $folder  = ($folder === '.' || $folder === '') ? '' : $folder;
        $name    = pathinfo($urlPath, PATHINFO_FILENAME);
        $ext     = pathinfo($urlPath, PATHINFO_EXTENSION);

        return $this->where('path', '=', $folder)
            ->where('name', '=', $name)
            ->where('extension', '=', $ext);
    }

    /**
     * Filter files by filename DTO.
     */
    public function whereFilename(Filename $filename): static
    {
        return $this->where('path', '=', $filename->path)
            ->where('name', '=', $filename->name)
            ->where('extension', '=', $filename->extension);
    }
}
