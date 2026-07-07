<?php

namespace AwtTechnology\FilamentAttachmentLibrary\ViewModels;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Livewire\Wireable;
use AwtTechnology\FilamentAttachmentLibrary\Enums\AttachmentType;
use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Facades\Glide;
use AwtTechnology\FilamentAttachmentLibrary\Facades\Resizer;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

class AttachmentViewModel implements Wireable
{
    /**
     * Loaded model when constructed from one (or after model() lazy-loads it).
     * Null for payload-hydrated instances — display fields carry the state.
     */
    public ?Attachment $attachment = null;

    public int $id;

    public string $name;

    public string $filename;

    public string $url;

    public ?string $path;

    public ?string $extension;

    public ?string $mimeType;

    public float $size;

    public ?string $createdBy;

    public ?CarbonInterface $createdAt;

    public ?string $updatedBy;

    public ?CarbonInterface $updatedAt;

    public ?string $title;

    public ?string $description;

    public ?string $alt;

    public ?string $caption;

    public ?int $bits = null;

    public ?int $channels = null;

    public ?string $dimensions = null;

    public function __construct(Attachment|array $source)
    {
        if (is_array($source)) {
            $this->fillFromPayload($source);
            return;
        }

        $attachment = $source;

        $userModel = Config::get('filament-attachment-library.user_model', User::class);
        $usernameProperty = Config::get('filament-attachment-library.username_property', 'name');

        $this->attachment = $attachment;

        $this->id = $attachment->id;
        $this->name = $attachment->name;
        $this->filename = $attachment->filename;
        $this->url = $attachment->url;
        $this->path = $attachment->path;
        $this->extension = Str::of($attachment->filename)->afterLast('.')->upper()->toString();
        $this->mimeType = $attachment->mime_type;
        $this->size = round($attachment->size / 1024 / 1024, 2);
        $this->createdBy = $attachment->created_by
            ? Cache::remember('attachment-user:' . $attachment->created_by, now()->addMinutes(5), fn () => $userModel::find($attachment->created_by)?->{$usernameProperty})
            : null;
        $this->createdAt = $attachment->created_at; // @phpstan-ignore-line
        $this->updatedBy = $attachment->updated_by
            ? Cache::remember('attachment-user:' . $attachment->updated_by, now()->addMinutes(5), fn () => $userModel::find($attachment->updated_by)?->{$usernameProperty})
            : null;
        $this->updatedAt = $attachment->updated_at; // @phpstan-ignore-line

        $this->title = $attachment->title;
        $this->description = $attachment->description;
        $this->alt = $attachment->alt;
        $this->caption = $attachment->caption;
    }

    private function fillFromPayload(array $payload): void
    {
        $this->id = $payload['id'];
        $this->name = $payload['name'];
        $this->filename = $payload['filename'];
        $this->url = $payload['url'];
        $this->path = $payload['path'];
        $this->extension = $payload['extension'];
        $this->mimeType = $payload['mimeType'];
        $this->size = $payload['size'];
        $this->createdBy = $payload['createdBy'];
        $this->createdAt = $payload['createdAt'] ? Carbon::parse($payload['createdAt']) : null;
        $this->updatedBy = $payload['updatedBy'];
        $this->updatedAt = $payload['updatedAt'] ? Carbon::parse($payload['updatedAt']) : null;
        $this->title = $payload['title'];
        $this->description = $payload['description'];
        $this->alt = $payload['alt'];
        $this->caption = $payload['caption'];
        $this->bits = $payload['bits'];
        $this->channels = $payload['channels'];
        $this->dimensions = $payload['dimensions'];
    }

    /**
     * The Eloquent model, loaded on first use. Payload-hydrated view models
     * only pay this query when something genuinely needs the model (e.g. a
     * thumbnail cache miss); plain re-renders never do.
     */
    public function model(): Attachment
    {
        return $this->attachment ??= Attachment::findOrFail($this->id);
    }

    public function isAttachment(): bool
    {
        return true;
    }

    public function isDirectory(): bool
    {
        return false;
    }

    public function isImage(): bool
    {
        return AttachmentManager::mimeTypeIsType($this->mimeType, AttachmentType::PREVIEWABLE_IMAGE);
    }

    public function isVideo(): bool
    {
        return AttachmentManager::mimeTypeIsType($this->mimeType, AttachmentType::PREVIEWABLE_VIDEO);
    }

    public function isDocument(): bool
    {
        return !$this->isVideo() && !$this->isImage();
    }

    public function isSelected(array $selected): bool
    {
        return in_array($this->id, $selected);
    }

    /**
     * Fill bits/channels/dimensions from the file's metadata. Deliberately not
     * called from the constructor: only the info panel needs these, and on a
     * cold cache the retrieval can mean downloading the whole file from the
     * CDN — far too expensive to pay per grid item.
     */
    public function loadMetadata(): static
    {
        try {
            if ($metadata = $this->attachment->metadata) { // @phpstan-ignore-line
                $this->bits = $metadata->bits;
                $this->channels = $metadata->channels;
                $this->dimensions = "{$metadata->width}x{$metadata->height}";
            }
        } catch (\Throwable $exception) {
            // A missing or unreadable file must not break the info panel;
            // the attachment simply shows without dimensions.
            report($exception);
        }

        return $this;
    }

    public function thumbnailUrl(): ?string
    {
        return Cache::remember(
            'attachment-thumbnail-url:' . $this->id . ':h320',
            now()->addDay(),
            function () {
                try {
                    $fullPath = implode('/', array_filter([$this->path, $this->filename]));
                    if (!Glide::imageIsSupported($fullPath)) {
                        return $this->url;
                    }
                    return Resizer::src($this->model())->height(320)->resize()['url'] ?? null;
                } catch (\Throwable $exception) {
                    // A single unreadable image must degrade to its original URL,
                    // not take down the whole browser page. The fallback is cached
                    // like a real thumbnail so a broken file is not retried per
                    // render; replacing the file clears the key via forgetCaches().
                    report($exception);
                    return $this->url;
                }
            }
        );
    }

    public function toLivewire()
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'filename'    => $this->filename,
            'url'         => $this->url,
            'path'        => $this->path,
            'extension'   => $this->extension,
            'mimeType'    => $this->mimeType,
            'size'        => $this->size,
            'createdBy'   => $this->createdBy,
            'createdAt'   => $this->createdAt?->toIso8601String(),
            'updatedBy'   => $this->updatedBy,
            'updatedAt'   => $this->updatedAt?->toIso8601String(),
            'title'       => $this->title,
            'description' => $this->description,
            'alt'         => $this->alt,
            'caption'     => $this->caption,
            'bits'        => $this->bits,
            'channels'    => $this->channels,
            'dimensions'  => $this->dimensions,
        ];
    }

    public static function fromLivewire($value): ?AttachmentViewModel
    {
        // Full payload: rebuild with zero queries.
        if (isset($value['name'])) {
            return new AttachmentViewModel($value);
        }

        // Legacy id-only payload (in-flight components from before this
        // change): fall back to a model load.
        $attachment = Attachment::find($value['id'] ?? null);

        return $attachment ? new AttachmentViewModel($attachment) : null;
    }

    /**
     * Resolve every uploader/updater name for a page of attachments in one
     * query, pre-warming the per-user cache the constructor reads. Missing
     * users are cached as '' so a deleted uploader does not re-query on
     * every render (Cache::remember never stores null).
     *
     * @param iterable<Attachment> $attachments
     */
    /**
     * Resolve every uploader/updater name for a page of attachments in one
     * query, pre-warming the per-user cache the constructor reads. Missing
     * users are cached as '' so a deleted uploader does not re-query on
     * every render (Cache::remember never stores null).
     *
     * @param iterable<Attachment> $attachments
     */
    public static function warmUserNames(iterable $attachments): void
    {
        $userIds = collect($attachments)
            ->flatMap(fn (Attachment $attachment) => [$attachment->created_by, $attachment->updated_by])
            ->filter()
            ->unique()
            ->reject(fn ($id) => Cache::has('attachment-user:' . $id))
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $userModel = Config::get('filament-attachment-library.user_model', User::class);
        $usernameProperty = Config::get('filament-attachment-library.username_property', 'name');

        $names = $userModel::query()
            ->whereIn('id', $userIds)
            ->pluck($usernameProperty, 'id');

        foreach ($userIds as $id) {
            Cache::put('attachment-user:' . $id, $names[$id] ?? '', now()->addMinutes(5));
        }
    }
}
