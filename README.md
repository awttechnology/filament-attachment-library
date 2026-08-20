# AwtTechnology — Filament Attachment Library

A standalone Filament 5 attachment library with BunnyCDN support and Glide image processing. This package is a fully self-contained fork of the VanOns attachment library packages, combining both `van-ons/laravel-attachment-library` and `van-ons/filament-attachment-library` into a single package under the `AwtTechnology` namespace with the following enhancements:

- **Remote disk support** — files are served via CDN URL rather than proxied through PHP.
- **Glide on remote disks** — Glide source and cache disks are resolved as Flysystem adapters, fixing `imageIsSupported()` for any non-local disk.
- **CDN image cache** — resized images are written to the configured cache disk (e.g. `bunny-glide`) and the CDN URL is returned directly, bypassing the GlideController on every subsequent request.
- **Object-storage directories** — `createDirectory()` always passes `CREATE_PARENT_DIRECTORIES` so virtual-prefix storage (BunnyCDN, S3) works without errors.
- **Hidden directories** — configure folders to exclude from the browser UI via `attachment-library.hidden_directories`.
- **Cacheable GD metadata** — `CacheableGd` stores image metadata as plain arrays so PHP's cache driver can serialise/deserialise them correctly.
- **Fast browser rendering** — image support is decided from the file extension (no Glide render per item), thumbnails and metadata failures are cached, uploader names resolve in one query per page, and view models rehydrate from their Livewire payload without database reads.

---

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Filament 5
- Livewire 3

---

## Installation

### 1. Require the package

```bash
composer require awttechnology/filament-attachment-library
```

### 2. Run the install command

Publishes config files and migrations:

```bash
php artisan filament-attachment-library:install
```

Or publish assets individually:

```bash
php artisan vendor:publish --tag=filament-attachment-library-config
php artisan vendor:publish --tag=filament-attachment-library-migrations
php artisan migrate
```

### 3. Configure your storage disk

Set the disk that will be used for attachments in `.env`:

```env
ATTACHMENTS_DISK=bunny
```

For BunnyCDN, configure the disk in `config/filesystems.php`:

```php
'bunny' => [
    'driver'       => 'bunny',
    'storage_zone' => env('BUNNY_STORAGE_ZONE'),
    'api_key'      => env('BUNNY_API_KEY'),
    'region'       => env('BUNNY_REGION', \PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNRegion::DEFAULT),
    'pull_zone'    => env('BUNNY_PULL_ZONE', ''),
    'root'         => '',
],

// Used by Glide to cache resized images under .glide/ on the CDN
'bunny-glide' => [
    'driver'       => 'bunny',
    'storage_zone' => env('BUNNY_STORAGE_ZONE'),
    'api_key'      => env('BUNNY_API_KEY'),
    'region'       => env('BUNNY_REGION', \PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNRegion::DEFAULT),
    'pull_zone'    => env('BUNNY_PULL_ZONE', ''),
    'root'         => '.glide',
],
```

Set the Glide cache disk in `config/glide.php`:

```php
'source'     => env('ATTACHMENTS_DISK', 'public'),
'cache_disk' => 'bunny-glide',
```

### 4. Register the Filament plugin

```php
// app/Providers/Filament/AdminPanelProvider.php

use AwtTechnology\FilamentAttachmentLibrary\FilamentAttachmentLibrary;

->plugins([
    FilamentAttachmentLibrary::make(),
    // Optional: set a navigation group
    // FilamentAttachmentLibrary::make()->navigationGroup('Media'),
    // Optional: restrict the browser to a base path
    // FilamentAttachmentLibrary::make()->basePath('media'),
])
```

### 5. Add the theme source

In your custom Filament theme CSS file, add:

```css
@source '../../../../vendor/awttechnology/filament-attachment-library/resources/**/*.blade.php';
```

Then rebuild your theme:

```bash
npm run build
```

---

## Configuration

### `config/attachment-library.php`

| Key | Default | Description |
|---|---|---|
| `disk` | `env('ATTACHMENTS_DISK', 'public')` | Storage disk for attachments |
| `auto_sync` | `true` | Auto-sync filesystem to database on browse |
| `auto_sync_interval` | `300` | Seconds between auto-syncs |
| `directory_source` | `'filesystem'` | Source for directory listing: `'filesystem'` or `'database'` |
| `hidden_directories` | `['.glide']` | Directory names hidden from the browser UI |
| `metadata_retrievers` | `[CacheableGd::class => ['image/*']]` | Metadata adapters mapped to MIME types |
| `class_mapping.attachment` | `Attachment::class` | Override the Attachment model |
| `class_mapping.attachment_manager` | `AttachmentManager::class` | Override the AttachmentManager |
| `class_mapping.directory` | `Directory::class` | Override the Directory DTO |

### `config/glide.php`

| Key | Default | Description |
|---|---|---|
| `driver` | `env('GLIDE_DRIVER', 'gd')` | Image driver: `gd` or `imagick` |
| `source` | `env('ATTACHMENTS_DISK', 'public')` | Disk name where originals are stored |
| `cache_disk` | `'bunny-glide'` | Disk name (or config array) for cached variants |
| `presets` | see config | Named resize presets |
| `breakpoints` | see config | Responsive breakpoints |
| `sizes` | see config | Named size ratios |
| `formats` | `['webp', 'jpg']` | Output formats, tried in order |

### `config/filament-attachment-library.php`

| Key | Default | Description |
|---|---|---|
| `user_model` | `User::class` | User model used for showing user information in the browser |
| `username_property` | `'name'` | Username property used for displaying usernames |
| `upload_rules` | `[]` | Additional Laravel validation rules for file uploads |
| `search_mode` | `'prefix'` | Browser search matching: `'prefix'` (indexed) or `'contains'` |

### Directory source

By default the browser lists directories by reading the filesystem. Set `directory_source` to `'database'` to derive directories from the `path` column of existing attachment records instead — useful when the filesystem is remote or slow, as it avoids Flysystem I/O entirely.

```php
// config/attachment-library.php
'directory_source' => 'database',
```

> **Note:** In database mode only directories that contain at least one attachment are visible. Empty directories will not appear.

### Hidden directories

Add any folder names you want to exclude from the attachment browser:

```php
// config/attachment-library.php
'hidden_directories' => [
    '.glide',
    '.system',
],
```

### Croppie cropper defaults

Defaults for [`CroppieAttachmentField`](#croppie-image-cropper-field). Every key is
overridable per-field via the field's fluent setters.

```php
// config/attachment-library.php
'croppie' => [
    'viewport_type'   => 'square', // 'square' | 'circle'
    'viewport_width'  => 200,
    'viewport_height' => 200,
    'boundary_width'  => 600,
    'boundary_height' => 400,
    'enable_resize'   => true,
    'enable_zoom'     => true,
    'show_zoomer'     => true,
    'modal_size'      => '4xl',
    'image_format'    => 'png',      // 'png' | 'jpeg' | 'webp'
    'image_size'      => 'viewport', // 'viewport' | 'original'
],
```

---

## Usage

### Attachment field in Filament forms

```php
use AwtTechnology\FilamentAttachmentLibrary\Forms\Components\AttachmentField;

// Store attachment ID in a model column
AttachmentField::make('featured_image'),

// Store attachments via the HasAttachments relationship
AttachmentField::make('gallery')->relationship(),

// Use a different collection name
AttachmentField::make('gallery')->relationship()->collection('product_gallery'),
```

#### Restricting file types

```php
AttachmentField::make('photo')->image(),   // image/*
AttachmentField::make('clip')->video(),    // video/*
AttachmentField::make('track')->audio(),   // audio/*
AttachmentField::make('document')->pdf(),  // application/pdf
AttachmentField::make('notes')->text(),    // text/*

// Or supply a custom MIME type
AttachmentField::make('file')->mime('application/zip'),
```

#### Setting a default directory

Open the browser at a specific directory instead of the storage root:

```php
AttachmentField::make('banner')->directory('images/banners'),

// Accepts a Closure for dynamic paths
AttachmentField::make('avatar')->directory(fn () => 'users/' . auth()->id()),
```

### Croppie image cropper field

`CroppieAttachmentField` **extends `AttachmentField`** — it keeps everything above
(browse the library, upload, `directory()`, `storeAsUrl()`, MIME restrictions,
`HasAttachments` relationships) and adds a **Crop** button. Clicking it loads the
currently-selected image into a [Croppie](https://foliotek.github.io/Croppie/)
modal; on save the cropped result is written to the field's disk/directory as a
**new** `Attachment` and the field's selection swaps to it. **The original image is
never modified** — cropping always produces a fresh derivative.

```php
use AwtTechnology\FilamentAttachmentLibrary\Forms\Components\CroppieAttachmentField;

// Minimal — square, resizable crop, stored as an attachment id
CroppieAttachmentField::make('avatar')->image(),

// Typical logo field: store the CDN URL, square viewport, on the bunny disk
CroppieAttachmentField::make('logo')
    ->image()
    ->disk('bunny')
    ->directory('logos')
    ->viewportType('square')
    ->enableResize(true)
    ->storeAsUrl(),

// A round avatar cropper (circular mask + circular PNG output)
CroppieAttachmentField::make('avatar')
    ->image()
    ->viewportType('circle')
    ->forceCircleResult(true)
    ->viewportWidth(240)
    ->viewportHeight(240),
```

#### Configuration methods

Every setter accepts a value or a `Closure`. Defaults come from the
`attachment-library.croppie` config block (see [Configuration](#croppie-cropper-defaults)).

| Method | Default | Description |
|---|---|---|
| `disk(string)` | `attachment-library.disk` | Disk the cropped copy is written to |
| `directory(string)` | *(none)* | Folder within the disk for the cropped copy (inherited from `AttachmentField`) |
| `imageName(string)` | *(auto)* | Base filename (no extension) for the cropped copy. When unset, `"{source-name}-cropped-{token}"` is used |
| `viewportType(string)` | `'square'` | `'square'` or `'circle'` |
| `viewportWidth(int)` / `viewportHeight(int)` | `200` | Crop viewport size in px |
| `boundaryWidth(int)` / `boundaryHeight(int)` | `600` / `400` | Croppie canvas size in px |
| `enableResize(bool)` | `true` | Let the user resize the viewport |
| `enableZoom(bool)` | `true` | Enable zooming |
| `showZoomer(bool)` | `true` | Show the zoom slider |
| `mouseWheelZoom(bool\|string)` | `true` | `true`, `false`, or `'ctrl'` |
| `enableOrientation(bool)` | `false` | Enable rotate/orientation controls |
| `forceCircleResult(bool)` | `false` | Output a circular (masked) image |
| `imageFormat(string)` | `'png'` | Output format: `'png'`, `'jpeg'`, `'webp'` |
| `imageSize(string)` | `'viewport'` | `'viewport'` (viewport-sized) or `'original'` (full-resolution crop region) |
| `modalTitle(string)` / `modalDescription(string)` / `modalSize(string)` | *(translated)* / `'4xl'` | Crop modal chrome |

#### How the cropped file is saved

The cropped image is produced client-side as base64, then decoded server-side and
written via `AttachmentManager::putContents()` — the raw-bytes sibling of `upload()`
that also registers the `Attachment` record. It lands at:

```
{disk}/{directory}/{source-name}-cropped-{random8}.{imageFormat}
```

The unique suffix means the original survives and there is no CDN/Glide cache to
bust (the derivative is a brand-new path). Because it is a first-class `Attachment`,
it shows up in the browser and works with `storeAsUrl()`, Glide, and the
`attachables` pivot exactly like any other attachment.

> **Cross-origin note (handled for you):** Croppie draws the source image to a
> `<canvas>`, so a cross-origin CDN image would taint the canvas and throw a
> `SecurityError`. The field sidesteps this by loading the crop source through the
> package's same-origin `attachment` proxy route, which now streams remote-disk
> (e.g. BunnyCDN) bytes. No CORS configuration on your CDN is required.

#### Assets

Croppie ships bundled with the package and is registered as a `loadedOnRequest()`
Filament asset, so it is only fetched when a crop field renders. After installing or
updating, publish the assets (the deploy step you already run for Filament):

```bash
php artisan filament:assets
```

### Remote file fetcher field

`RemoteFileFetcher` is a Filament form field that downloads a file from a remote URL, stores it on a configured disk, and creates an `Attachment` record — all from within the Filament admin panel.

```php
use AwtTechnology\FilamentAttachmentLibrary\Forms\Components\RemoteFileFetcher;

RemoteFileFetcher::make('field_name')
    ->disk('public')       // Storage disk (default: 'public')
    ->folder('uploads')    // Folder within the disk (created automatically if missing)
    ->fileType('image'),   // Restrict to 'image', 'pdf', or omit for any file type
```

The field renders a **Remote URL** input, a target folder hint, and a **Local Filename** input with a **Fetch File** button. On success, the field state is set to the stored path and an `Attachment` record is created.

#### Syncing to an AttachmentField

Use `->updateAttachmentField()` to automatically set a sibling `AttachmentField` to the newly created attachment after a successful fetch:

```php
AttachmentField::make('pdf_file')
    ->pdf()
    ->directory('brochures')
    ->storeAsUrl(),

RemoteFileFetcher::make('fetch_pdf')
    ->updateAttachmentField('pdf_file')  // name of the sibling AttachmentField
    ->disk('bunny')
    ->folder('brochures')
    ->fileType('pdf'),
```

> `storeAsUrl()` stores the attachment's public URL in the column and resolves it
> back to the attachment on load via an indexed lookup. Single-select fields only.
> It replaces the previous `dehydrateStateUsing`/`formatStateUsing` recipe, which
> loaded the entire attachments table and silently produced `null` on any URL
> mismatch (the field then appeared to "lose" its value).

The `attachment_id` returned from the fetch is written directly to the sibling field's Livewire state, so the `AttachmentField` reflects the new file without a page reload.

**Validation performed before fetching:**
- URL format is validated using Laravel's `url` rule
- A HEAD request confirms the URL is reachable and checks the `Content-Type` header against the configured file type restriction
- If a file with the given filename already exists at the destination, an error is shown and the fetch is skipped

**Extension correction:**
The filename extension is automatically corrected if it does not match the remote file's actual extension (derived first from the remote URL path, then from the `Content-Type` header). For example, providing `logo.png` for a BMP image will silently rename it to `logo.bmp`.

**Supported `fileType()` values:**

| Value | Accepted MIME types |
|---|---|
| `'image'` | `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/svg+xml` |
| `'pdf'` | `application/pdf` |
| `null` *(default)* | Any content type |

### HasAttachments trait

Add the trait to any model to get an `attachments()` polymorphic relationship:

```php
use AwtTechnology\FilamentAttachmentLibrary\Concerns\HasAttachments;

class Post extends Model
{
    use HasAttachments;

    // Optional: typed collection relationship
    public function gallery(): MorphToMany
    {
        return $this->attachmentCollection('gallery');
    }
}
```

### Responsive image component

```blade
{{-- Renders a <picture> element with responsive srcset and WebP/JPEG sources --}}
<x-filament-attachment-library-image :src="$attachment" />
<x-filament-attachment-library-image :src="$attachment->id" size="large" aspect-ratio="16/9" />
```

### AttachmentManager facade

```php
use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;

$attachment = AttachmentManager::upload($uploadedFile, 'images/products');
$url        = AttachmentManager::getUrl($attachment);
$dirs       = AttachmentManager::directories('images');

// Persist raw bytes (e.g. a generated/cropped image) and register the
// Attachment record — the raw-bytes sibling of upload(). Honours setDisk().
$attachment = AttachmentManager::setDisk('bunny')->putContents(
    contents: $pngBytes,
    mimeType: 'image/png',
    directory: 'logos',
    name: 'acme-logo',   // optional; a UUID is used when omitted
    extension: 'png',
);
```

### URL-based attachment lookup

`Attachment::findByUrl()` and the `whereUrl()` query scope reverse a public URL back to its `path`, `name`, and `extension` columns so the lookup hits an indexed query instead of loading the entire table into PHP.

```php
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

// Returns the matching Attachment or null — no full-table fetch
$attachment = Attachment::findByUrl('https://cdn.example.com/hotels/hero.jpg');

// The underlying query scope is also available directly
$attachment = Attachment::query()->whereUrl($url)->first();
```

This is the right tool when you have a stored CDN URL and need the corresponding `Attachment` record, for example when syncing data from an external system that only recorded the URL.

---

## Artisan commands

```bash
# Clear all Glide image cache
php artisan glide:clear

# Show Glide cache statistics
php artisan glide:stats

# Pre-warm the Glide thumbnail cache for all image attachments
php artisan glide:warm
```

### `glide:warm`

Iterates every `image/*` attachment and generates its h320 thumbnail, writing the result to the Glide cache disk and storing the URL in the Laravel cache. Running this after a fresh deploy or cache flush prevents the first open of the attachment picker from triggering slow on-demand resizes for every image.

| Option | Default | Description |
|---|---|---|
| `--skip-existing` | — | Skip any attachment whose thumbnail URL is already in the cache |
| `--preset=<name>` | — | Also warm a named preset from `config('glide.presets')` for each attachment |
| `--chunk=<n>` | `100` | Batch size passed to `chunkById` |

```bash
# Warm only attachments not yet cached
php artisan glide:warm --skip-existing

# Warm the h320 thumbnail and a custom preset
php artisan glide:warm --preset=hero

# Use a smaller batch size to reduce peak memory
php artisan glide:warm --chunk=50
```

The command prints a progress bar while running and finishes with a summary table of warmed / skipped / failed counts. If a preset name is given that does not exist in `config('glide.presets')`, the command exits immediately with an error.

---

## Testing

```bash
composer test      # Pest test suite (Orchestra Testbench)
composer analyse   # PHPStan level 5
```

---

## Extending

Override the `AttachmentManager` by swapping the class in config:

```php
// config/attachment-library.php
'class_mapping' => [
    'attachment_manager' => \App\Support\CustomAttachmentManager::class,
],
```

Your class must extend `AwtTechnology\FilamentAttachmentLibrary\AttachmentManager`.
