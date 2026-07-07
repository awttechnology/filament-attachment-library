# Browser Hot Path (Phase 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the attachment browser render with zero filesystem/CDN calls when caches are warm and a small constant query count — eliminating the per-item remote work identified as P1, P2, P3, P5, P7 in the spec.

**Architecture:** Five independent hot-path fixes: `imageIsSupported()` decides from the driver's supported-format list instead of generating an image (P2); image metadata leaves the view-model constructor and loads on demand in the info panel (P1); the view model becomes a self-contained Wireable that rehydrates from its payload without touching the DB, with a lazy model accessor for the rare paths that need one (P3); user display names resolve in one batched query per page (P7); search uses an indexed prefix match by default with contains as config opt-in (P5). A final task tightens the Phase-0 query-budget tripwire and adds a warm-cache "no Glide server" guard.

**Tech Stack:** PHP 8.2+, Laravel 12 (Testbench 10), Filament 5, Livewire 4, Pest 3, PHPStan level 5 (larastan).

**Spec:** `docs/superpowers/specs/2026-07-07-performance-reliability-design.md` (Phase 2 section). Phase 1 (harness + correctness) is merged; suite is currently 36 tests / 75 assertions green.

## Global Constraints

- No public API removals; additive changes only. One sanctioned widening: `AttachmentViewModel::$attachment` becomes `?Attachment` (nullable) — documented in Task 3.
- `AttachmentViewModel::__construct(Attachment $attachment)` must keep accepting an `Attachment`; existing `new AttachmentViewModel($model)` call sites in `AttachmentBrowser`, `AttachmentInfo`, `AttachmentField` keep working unchanged.
- `GlideManager::imageIsSupported(string $path, array $params = []): bool` signature unchanged.
- Every fix lands red → green (TDD); run the full suite (`composer test`) before each commit; `composer analyse` must stay clean — fix flagged code, never baseline it.
- Test disk is `attachments`; `makeAttachment()` (tests/Pest.php) creates a DB row + real ~287-byte JPEG on the fake disk; rows with other disk names are DB-only.
- All commands run from the repo root: `/home/tohir-solomons/websites/filament-attachment-library`.
- Do not start Phase 3 work (no schema changes, no queued jobs).

---

### Task 1: P2 — `imageIsSupported()` without generating an image

**Files:**
- Modify: `src/Glide/GlideManager.php:106-118` (`imageIsSupported`)
- Test: `tests/Feature/ImageIsSupportedTest.php`

**Interfaces:**
- Consumes: `GlideManager::getSupportedImageFormats(bool $onlyCommon = true): array` (existing; returns uppercase format names like `['GIF','JPEG','JPG','PNG','WEBP',...]`, cached one day).
- Produces: `imageIsSupported(string $path, array $params = []): bool` (same signature) now answers from the file extension against the supported-format list — no `server()`, no `makeImage()`, no filesystem I/O. `AttachmentViewModel::thumbnailUrl()` continues to call it unchanged.

Today `imageIsSupported()` calls `$this->server()->makeImage($path, $params)` — generating a full-size variant on the cache disk just to learn whether a file is an image — and caches the verdict only 5 minutes. Decode failures no longer need this probe: since Phase 1, `thumbnailUrl()` catches resize failures and caches the fallback URL for a day (the long-TTL negative cache the spec asks to keep).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ImageIsSupportedTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Facades\Glide;
use League\Glide\Server;

it('answers from the extension without touching the Glide server', function () {
    // The file deliberately does NOT exist on the source disk. The old
    // implementation called makeImage(), which throws for a missing file and
    // returned false; the format-based check answers true for a .jpg path.
    expect(Glide::imageIsSupported('nonexistent/photo.jpg'))->toBeTrue();
});

it('rejects extensions the driver cannot decode', function () {
    expect(Glide::imageIsSupported('docs/report.pdf'))->toBeFalse()
        ->and(Glide::imageIsSupported('archive/backup.zip'))->toBeFalse();
});

it('rejects paths with no extension', function () {
    expect(Glide::imageIsSupported('somefile'))->toBeFalse();
});

it('never builds the Glide server for a support check', function () {
    // Binding a throwing Server factory proves imageIsSupported() no longer
    // resolves the server at all.
    app()->bind(Server::class, function () {
        throw new RuntimeException('server should not be built');
    });

    expect(Glide::imageIsSupported('images/photo.png'))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/ImageIsSupportedTest.php`
Expected: test 1 FAILS (missing file → makeImage throws → false). Test 4 may pass by accident only if `GlideManager::server()` is already memoized — it is a fresh app per test, so it FAILS with the RuntimeException escaping via `makeImage`. Tests 2–3 may already pass (unsupported formats made `makeImage` throw); keep them as regression pins.

Note: test 4 as written binds `League\Glide\Server`, but `GlideManager::server()` builds via `ServerFactory::create()`, not the container binding. If test 4 passes pre-fix for that reason, replace its body with a partial mock: `Glide::partialMock()->shouldNotReceive('server');` then the expect line — that pins the same contract. Verify which mechanism actually fails pre-fix and keep that one; note the choice in your report.

- [ ] **Step 3: Replace the implementation**

In `src/Glide/GlideManager.php`, replace `imageIsSupported()`:

```php
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

        return in_array($extension, $this->getSupportedImageFormats(), true);
    }
```

Remove the now-unused `use Exception;` import if nothing else in the file uses it (check `cacheStats()` first).

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/ImageIsSupportedTest.php`
Expected: all PASS. Then `composer test` (all green — note: if any existing test asserted the old missing-file→false behaviour, read it and update only with justification in your report) and `composer analyse`.

- [ ] **Step 5: Commit**

```bash
git add src/Glide/GlideManager.php tests/Feature/ImageIsSupportedTest.php
git commit -m "perf: decide image support from extension, not a Glide render"
```

---

### Task 2: P1 — metadata leaves the view-model constructor

**Files:**
- Modify: `src/ViewModels/AttachmentViewModel.php:87-97` (constructor) — add `loadMetadata()`
- Modify: `src/Livewire/AttachmentInfo.php:36-47` (`highlightAttachment`)
- Test: `tests/Feature/LazyMetadataTest.php`

**Interfaces:**
- Consumes: `Attachment::$metadata` accessor (returns `FileMetadata|false`, failure cached as `false` since Phase 1); `AttachmentViewModel` public properties `?int $bits`, `?int $channels`, `?string $dimensions` (all default `null`, read only by `resources/views/livewire/attachment-info.blade.php`).
- Produces: `AttachmentViewModel::loadMetadata(): static` — fills `bits`/`channels`/`dimensions` from `$attachment->metadata`, swallowing-and-reporting failures exactly as the constructor does today. The constructor no longer touches metadata at all. Task 3 will carry the three fields in the Wireable payload.

The grid and list views never read `bits`/`channels`/`dimensions` — only the info panel does. Today every one of the 25 items on a browser page pays the metadata cost (on a cold cache: a full file download from the CDN to run `getimagesize()`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/LazyMetadataTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentInfo;
use AwtTechnology\FilamentAttachmentLibrary\ViewModels\AttachmentViewModel;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

it('does not retrieve metadata when constructing the view model', function () {
    $attachment = makeAttachment(['name' => 'lazy']);
    $metadataKey = 'metadata-adapter-' . hash('sha256', $attachment->absolute_path);

    new AttachmentViewModel($attachment);

    // Constructing must not have populated the metadata cache — proof the
    // adapter (and its potential remote file read) never ran.
    expect(Cache::has($metadataKey))->toBeFalse();
});

it('loads metadata on demand via loadMetadata', function () {
    $attachment = makeAttachment(['name' => 'eager']);

    $viewModel = (new AttachmentViewModel($attachment))->loadMetadata();

    expect($viewModel->dimensions)->toBe('1x1')
        ->and($viewModel->bits)->not->toBeNull();
});

it('still shows dimensions in the info panel', function () {
    $attachment = makeAttachment(['name' => 'panel']);

    Livewire::test(AttachmentInfo::class)
        ->call('highlightAttachment', $attachment->id)
        ->assertSet('attachment.dimensions', '1x1');
});
```

(The fixture JPEG is 1x1 — `dimensions` is deterministic.)

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/LazyMetadataTest.php`
Expected: test 1 FAILS (constructor populates the cache today); test 2 FAILS (`loadMetadata` undefined); test 3 passes today — it pins the behaviour Task 2 must not break.

- [ ] **Step 3: Move the metadata block into loadMetadata()**

In `src/ViewModels/AttachmentViewModel.php`, delete the `try { if ($metadata = ...) ... } catch` block from the constructor (lines 87-97) and add this method after `isSelected()`:

```php
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
```

- [ ] **Step 4: Call it from the info panel**

In `src/Livewire/AttachmentInfo.php`, `highlightAttachment()`:

```php
        $this->attachment = (new AttachmentViewModel($attachment))->loadMetadata();
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/LazyMetadataTest.php`
Expected: all PASS. Then `composer test` and `composer analyse` — green. (`tests/Feature/ThumbnailFallbackTest.php`'s metadata-cache test calls `$attachment->metadata` directly, not the view model — unaffected.)

- [ ] **Step 6: Commit**

```bash
git add src/ViewModels/AttachmentViewModel.php src/Livewire/AttachmentInfo.php tests/Feature/LazyMetadataTest.php
git commit -m "perf: load view-model metadata on demand instead of per grid item"
```

---

### Task 3: P3 — DB-free Wireable rehydration

**Files:**
- Modify: `src/ViewModels/AttachmentViewModel.php` (constructor, `$attachment` property, `isImage`/`isVideo`/`isSelected`, `thumbnailUrl`, `toLivewire`, `fromLivewire`)
- Modify: `src/AttachmentManager.php` (add `mimeTypeIsType()`)
- Modify: `resources/views/components/attachment/browser-actions.blade.php` (5 `$attachment->attachment->` reads)
- Test: `tests/Feature/ViewModelWireableTest.php`

**Interfaces:**
- Consumes: `AttachmentType::PREVIEWABLE_IMAGE` / `PREVIEWABLE_VIDEO` string constants; `config('attachment-library.attachment_mime_type_mapping')` (type constant → mime array); Task 2's `loadMetadata()` fields.
- Produces:
  - `AttachmentManager::mimeTypeIsType(?string $mimeType, string $type): bool` — pure mime-mapping check; existing `isType(Attachment, string)` delegates to it.
  - `AttachmentViewModel::__construct(Attachment|array $source)` — array form fills all display fields from a `toLivewire()` payload without any query.
  - `AttachmentViewModel::model(): Attachment` — lazy accessor; loads by id once when a caller genuinely needs the Eloquent model (thumbnail cache miss), memoizes into `$this->attachment`.
  - `public ?Attachment $attachment` — now nullable (sanctioned widening; in-package blade views stop reading through it).
  - `toLivewire(): array` returns every display field; `fromLivewire($value): ?AttachmentViewModel` rebuilds without touching the DB (legacy `['id' => N]` payloads still fall back to a model load).

Today `fromLivewire()` runs `Attachment::find()` + the full constructor per item per Livewire request — the N+1 lives in `AttachmentInfo`'s `public ?AttachmentViewModel $attachment` property, which round-trips on every interaction with the info panel open.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ViewModelWireableTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentInfo;
use AwtTechnology\FilamentAttachmentLibrary\ViewModels\AttachmentViewModel;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('rehydrates from its payload without querying the database', function () {
    $attachment = makeAttachment(['name' => 'wired', 'path' => 'docs']);
    $payload = (new AttachmentViewModel($attachment))->loadMetadata()->toLivewire();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $rebuilt = AttachmentViewModel::fromLivewire($payload);

    expect($queries)->toBe(0)
        ->and($rebuilt->id)->toBe($attachment->id)
        ->and($rebuilt->name)->toBe('wired')
        ->and($rebuilt->url)->toBe($attachment->url)
        ->and($rebuilt->mimeType)->toBe('image/jpeg')
        ->and($rebuilt->dimensions)->toBe('1x1')
        ->and($rebuilt->isImage())->toBeTrue()
        ->and($rebuilt->isVideo())->toBeFalse()
        ->and($rebuilt->isSelected([$attachment->id]))->toBeTrue()
        ->and($rebuilt->createdAt?->equalTo($attachment->created_at))->toBeTrue();
});

it('still hydrates legacy id-only payloads', function () {
    $attachment = makeAttachment(['name' => 'legacy']);

    $rebuilt = AttachmentViewModel::fromLivewire(['id' => $attachment->id]);

    expect($rebuilt->name)->toBe('legacy');
});

it('does not query the attachments table when re-rendering the info panel', function () {
    $attachment = makeAttachment(['name' => 'panel-rerender']);

    $component = Livewire::test(AttachmentInfo::class)
        ->call('highlightAttachment', $attachment->id);

    $attachmentQueries = 0;
    DB::listen(function ($query) use (&$attachmentQueries) {
        if (str_contains($query->sql, 'attachments')) {
            $attachmentQueries++;
        }
    });

    $component->call('$refresh');

    expect($attachmentQueries)->toBe(0);
});

it('lazily loads the model when a caller needs it', function () {
    $attachment = makeAttachment(['name' => 'lazy-model']);
    $rebuilt = AttachmentViewModel::fromLivewire(
        (new AttachmentViewModel($attachment))->toLivewire()
    );

    expect($rebuilt->model()->getKey())->toBe($attachment->getKey());
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/ViewModelWireableTest.php`
Expected: test 1 FAILS (payload is `['id' => N]`; fromLivewire queries); test 3 FAILS (re-render re-runs `Attachment::find`); test 4 FAILS (`model()` undefined); test 2 passes (that IS today's path) — keep as the legacy pin.

- [ ] **Step 3: Add the pure mime check to AttachmentManager**

In `src/AttachmentManager.php`, next to `isType()`:

```php
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
```

- [ ] **Step 4: Rework the view model**

In `src/ViewModels/AttachmentViewModel.php`:

a. Property and imports:

```php
use Illuminate\Support\Carbon;
use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
```

```php
    /**
     * Loaded model when constructed from one (or after model() lazy-loads it).
     * Null for payload-hydrated instances — display fields carry the state.
     */
    public ?Attachment $attachment = null;
```

b. Constructor accepts the payload form; the model branch keeps its existing body (minus the metadata block removed in Task 2):

```php
    public function __construct(Attachment|array $source)
    {
        if (is_array($source)) {
            $this->fillFromPayload($source);
            return;
        }

        // The existing model-based body stays byte-for-byte as it is in the
        // file after Task 2 (assignments for id, name, filename, url, path,
        // extension, mimeType, size, createdBy, createdAt, updatedBy,
        // updatedAt, title, description, alt, caption — and nothing else).
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
```

c. Lazy model accessor (used by `thumbnailUrl()` on cache miss and available to actions):

```php
    /**
     * The Eloquent model, loaded on first use. Payload-hydrated view models
     * only pay this query when something genuinely needs the model (e.g. a
     * thumbnail cache miss); plain re-renders never do.
     */
    public function model(): Attachment
    {
        return $this->attachment ??= Attachment::findOrFail($this->id);
    }
```

d. Model-free type checks and selection:

```php
    public function isImage(): bool
    {
        return AttachmentManager::mimeTypeIsType($this->mimeType, AttachmentType::PREVIEWABLE_IMAGE);
    }

    public function isVideo(): bool
    {
        return AttachmentManager::mimeTypeIsType($this->mimeType, AttachmentType::PREVIEWABLE_VIDEO);
    }

    public function isSelected(array $selected): bool
    {
        return in_array($this->id, $selected);
    }
```

e. `thumbnailUrl()` — same logic, model only on cache miss. `full_path` is derivable without the model:

```php
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
```

f. Wireable round-trip:

```php
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
```

- [ ] **Step 5: Stop reading through ->attachment in the blade**

In `resources/views/components/attachment/browser-actions.blade.php`, replace all five reads:
- `$attachment->attachment->id` → `$attachment->id` (4 occurrences)
- `:href="$attachment->attachment->url"` → `:href="$attachment->url"` (1 occurrence)

Then verify nothing else in the package reads through the model property:
Run: `grep -rn -- '->attachment->' resources/views/ src/`
Expected: no hits outside `src/ViewModels/AttachmentViewModel.php` itself.

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Feature/ViewModelWireableTest.php`
Expected: all PASS. Then `composer test` (all green) and `composer analyse` (clean; if the nullable `$attachment` trips baseline'd rules, fix the touched code).

- [ ] **Step 7: Commit**

```bash
git add src/ViewModels/AttachmentViewModel.php src/AttachmentManager.php resources/views/components/attachment/browser-actions.blade.php tests/Feature/ViewModelWireableTest.php
git commit -m "perf: rehydrate attachment view models from their payload, not the DB"
```

---

### Task 4: P7 — batch user-name resolution

**Files:**
- Modify: `src/ViewModels/AttachmentViewModel.php` (add static `warmUserNames()`)
- Modify: `src/Livewire/AttachmentBrowser.php:394-397` (`getAttachments`)
- Test: `tests/Feature/BatchedUserNamesTest.php`

**Interfaces:**
- Consumes: existing per-user cache keys `attachment-user:{id}` (5-minute TTL) read by the view-model constructor via `Cache::remember`; `config('filament-attachment-library.user_model')` (default `Illuminate\Foundation\Auth\User`) and `username_property` (default `name`).
- Produces: `AttachmentViewModel::warmUserNames(iterable $attachments): void` — collects distinct `created_by`/`updated_by` ids, loads all uncached names in ONE `whereIn` query, and `Cache::put`s each (missing users cached as `''` so they don't re-query). The constructor's `Cache::remember` then hits warm cache. Browser calls it before mapping models to view models.

Today each distinct user id costs one query per 5 minutes per process — fine for two users, a query pile-up for a page of attachments uploaded by many people.

- [ ] **Step 1: Write the failing test**

`tests/Feature/BatchedUserNamesTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});

function makeUser(string $name): int
{
    return DB::table('users')->insertGetId([
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('resolves all uploader names in a single query per render', function () {
    $ids = collect(range(1, 5))->map(fn ($i) => makeUser("User {$i}"));
    foreach ($ids as $i => $userId) {
        makeAttachment(['name' => "file-{$i}", 'created_by' => $userId, 'updated_by' => $userId]);
    }

    $userQueries = 0;
    DB::listen(function ($query) use (&$userQueries) {
        if (str_contains($query->sql, 'users')) {
            $userQueries++;
        }
    });

    Livewire::test(AttachmentBrowser::class)->assertHasNoErrors();

    expect($userQueries)->toBe(1);
});

it('does not re-query users whose names are already cached', function () {
    $userId = makeUser('Cached User');
    makeAttachment(['name' => 'file-a', 'created_by' => $userId]);

    Livewire::test(AttachmentBrowser::class)->assertHasNoErrors();

    $userQueries = 0;
    DB::listen(function ($query) use (&$userQueries) {
        if (str_contains($query->sql, 'users')) {
            $userQueries++;
        }
    });

    Livewire::test(AttachmentBrowser::class)->assertHasNoErrors();

    expect($userQueries)->toBe(0);
});

it('caches missing users so they are not re-queried', function () {
    makeAttachment(['name' => 'orphan', 'created_by' => 999]);

    Livewire::test(AttachmentBrowser::class)->assertHasNoErrors();

    $userQueries = 0;
    DB::listen(function ($query) use (&$userQueries) {
        if (str_contains($query->sql, 'users')) {
            $userQueries++;
        }
    });

    Livewire::test(AttachmentBrowser::class)->assertHasNoErrors();

    expect($userQueries)->toBe(0);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/BatchedUserNamesTest.php`
Expected: test 1 FAILS (5 separate `users` queries via per-item `Cache::remember`); test 3 FAILS (`Cache::remember` never stores null, so the orphan re-queries). Test 2 passes today (array-cache within process) — keep as a pin.

- [ ] **Step 3: Implement warmUserNames()**

In `src/ViewModels/AttachmentViewModel.php` (after `fromLivewire()`):

```php
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
```

- [ ] **Step 4: Call it from the browser**

In `src/Livewire/AttachmentBrowser.php`, `getAttachments()`, before the map:

```php
        AttachmentViewModel::warmUserNames($attachments->items());

        $collection = $attachments->getCollection()
            ->map(fn (Attachment $attachment) => new AttachmentViewModel($attachment));
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/BatchedUserNamesTest.php`
Expected: all PASS. Then `composer test` and `composer analyse` — green.

- [ ] **Step 6: Commit**

```bash
git add src/ViewModels/AttachmentViewModel.php src/Livewire/AttachmentBrowser.php tests/Feature/BatchedUserNamesTest.php
git commit -m "perf: resolve uploader names in one query per page"
```

---

### Task 5: P5 — indexed prefix search by default

**Files:**
- Modify: `src/Livewire/AttachmentBrowser.php:381-384` (search branch in `getAttachments`)
- Modify: `config/filament-attachment-library.php` (add `search_mode`)
- Modify: `README.md` (config table row)
- Test: `tests/Feature/SearchModeTest.php`

**Interfaces:**
- Consumes: `config('filament-attachment-library.search_mode')`.
- Produces: search matches `name LIKE 'term%'` by default (uses the `path_name_extension` index); `'search_mode' => 'contains'` restores the old `%term%` behaviour.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/SearchModeTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use Livewire\Livewire;

beforeEach(function () {
    makeAttachment(['name' => 'hero']);
    makeAttachment(['name' => 'my-hero']);
});

it('matches by prefix by default so the name index is usable', function () {
    Livewire::test(AttachmentBrowser::class)
        ->set('search', 'hero')
        ->assertSee('hero')
        ->assertDontSee('my-hero');
});

it('matches anywhere when search_mode is contains', function () {
    config()->set('filament-attachment-library.search_mode', 'contains');

    Livewire::test(AttachmentBrowser::class)
        ->set('search', 'hero')
        ->assertSee('my-hero');
});
```

Caution: `assertSee('hero')` also matches the substring inside `my-hero`; assertions above are ordered so the discriminating one is `assertDontSee('my-hero')` in test 1 and `assertSee('my-hero')` in test 2. If Livewire's HTML escaping makes `assertDontSee('my-hero')` flaky, switch both tests to inspecting `viewData('attachments')` names instead — note the choice in your report.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/SearchModeTest.php`
Expected: test 1 FAILS (contains-match finds `my-hero`); test 2 passes today — keep as the opt-in pin.

- [ ] **Step 3: Implement**

In `src/Livewire/AttachmentBrowser.php`, replace the search branch:

```php
            ->when($this->search, function (Builder $query) {
                // Prefix match by default: 'term%' can use the name index,
                // '%term%' forces a full scan. Set search_mode to 'contains'
                // to restore substring matching.
                $term = Config::get('filament-attachment-library.search_mode', 'prefix') === 'contains'
                    ? '%' . $this->search . '%'
                    : $this->search . '%';
                $query->where('name', 'like', $term);
            })
```

In `config/filament-attachment-library.php`:

```php
    /**
     * How the browser's search box matches attachment names.
     *
     * 'prefix'   — name LIKE 'term%' (default; can use the name index)
     * 'contains' — name LIKE '%term%' (matches anywhere; full scan on large tables)
     */
    'search_mode' => 'prefix',
```

In `README.md`, add to the `config/filament-attachment-library.php` area (create a small table if none exists for this file; there is one for `attachment-library.php` at line ~121 to mirror):

```markdown
| `search_mode` | `'prefix'` | Browser search matching: `'prefix'` (indexed) or `'contains'` |
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/SearchModeTest.php`
Expected: PASS. Then `composer test` and `composer analyse` — green.

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/AttachmentBrowser.php config/filament-attachment-library.php README.md tests/Feature/SearchModeTest.php
git commit -m "perf: prefix search by default with contains as config opt-in"
```

---

### Task 6: Tighten the query budget + warm-cache render guard

**Files:**
- Modify: `tests/Feature/BrowserQueryBudgetTest.php`

**Interfaces:**
- Consumes: everything Tasks 1–5 delivered; `Glide::partialMock()` (facade for `attachment.glide.manager`).
- Produces: the Phase-2 exit criteria as executable guards — the spec's "≤ ~6 queries per page" and "zero filesystem/CDN calls when caches are warm".

- [ ] **Step 1: Tighten the existing budget and add the warm-render guard**

Replace `tests/Feature/BrowserQueryBudgetTest.php` with:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use AwtTechnology\FilamentAttachmentLibrary\Facades\Glide;
use AwtTechnology\FilamentAttachmentLibrary\ViewModels\AttachmentViewModel;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('renders a full page of attachments within the query budget', function () {
    foreach (range(1, 30) as $i) {
        makeAttachment(['name' => "img-{$i}"]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    Livewire::test(AttachmentBrowser::class)->assertHasNoErrors();

    // Phase 2 exit criterion (spec): a small constant per page regardless of
    // page size. Phase 1 baseline was 60.
    expect($queries)->toBeLessThanOrEqual(10);
});

it('never touches the Glide server when thumbnails are warm', function () {
    $attachments = collect(range(1, 5))->map(fn ($i) => makeAttachment(['name' => "warm-{$i}"]));

    // Warm every thumbnail key the page will read.
    foreach ($attachments as $attachment) {
        cache()->put(
            'attachment-thumbnail-url:' . $attachment->id . ':h320',
            'https://cdn.example.com/warm.jpg',
            now()->addDay()
        );
    }

    // Real GlideManager logic runs, but building the Glide server (the
    // gateway to every filesystem/CDN read or write) is forbidden.
    Glide::partialMock()->shouldNotReceive('server');

    Livewire::test(AttachmentBrowser::class)->assertHasNoErrors();
});
```

- [ ] **Step 2: Run**

Run: `vendor/bin/pest tests/Feature/BrowserQueryBudgetTest.php`
Expected: both PASS. If the budget test exceeds 10, print the actual count and the captured SQL (temporarily log `$query->sql` in the listener), identify which task left a per-item query, and fix that source — do NOT raise the budget. If the warm test fails because something still resolves `server()`, trace and fix it.

- [ ] **Step 3: Full suite + commit**

Run: `composer test && composer analyse`
Expected: all green.

```bash
git add tests/Feature/BrowserQueryBudgetTest.php
git commit -m "test: enforce Phase 2 exit criteria — query budget 10 and no Glide server when warm"
```

---

### Task 7: Final verification + docs

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: everything above.
- Produces: green suite + analysis; README reflects the new behaviour.

- [ ] **Step 1: Full verification**

Run: `composer test && composer analyse`
Expected: every test passes (expect ~50 tests), PHPStan clean. If anything fails, STOP and fix before the docs step.

- [ ] **Step 2: README notes**

In `README.md`:

a. In the feature bullet list at the top, add:

```markdown
- **Fast browser rendering** — image support is decided from the file extension (no Glide render per item), thumbnails and metadata failures are cached, uploader names resolve in one query per page, and view models rehydrate from their Livewire payload without database reads.
```

b. Verify the `search_mode` row from Task 5 is present in the config documentation; add it if Task 5's README edit was missed.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: describe Phase 2 browser performance behaviour"
```

- [ ] **Step 4: Exit-criteria checklist (controller)**

Spec Phase 2 requires: P1 (metadata out of the render path — Task 2), P2 (no makeImage probe — Task 1), P3 (DB-free rehydration — Task 3), P5 (prefix search — Task 5), P7 (batched user names — Task 4), and the measurement re-run (Task 6: budget ≤ 10, warm render touches no Glide server). Phase 3 (persisted dimensions, queued thumbnails, streamed uploads) is a separate plan.
