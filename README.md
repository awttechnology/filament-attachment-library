# AwtTechnology — Filament Attachment Library

A standalone Filament 5 attachment library with BunnyCDN support and Glide image processing. This package is a fully self-contained fork of the VanOns attachment library packages, combining both `van-ons/laravel-attachment-library` and `van-ons/filament-attachment-library` into a single package under the `AwtTechnology` namespace with the following enhancements:

- **Remote disk support** — files are served via CDN URL rather than proxied through PHP.
- **Glide on remote disks** — Glide source and cache disks are resolved as Flysystem adapters, fixing `imageIsSupported()` for any non-local disk.
- **CDN image cache** — resized images are written to the configured cache disk (e.g. `bunny-glide`) and the CDN URL is returned directly, bypassing the GlideController on every subsequent request.
- **Object-storage directories** — `createDirectory()` always passes `CREATE_PARENT_DIRECTORIES` so virtual-prefix storage (BunnyCDN, S3) works without errors.
- **Hidden directories** — configure folders to exclude from the browser UI via `attachment-library.hidden_directories`.
- **Cacheable GD metadata** — `CacheableGd` stores image metadata as plain arrays so PHP's cache driver can serialise/deserialise them correctly.

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

### Hidden directories

Add any folder names you want to exclude from the attachment browser:

```php
// config/attachment-library.php
'hidden_directories' => [
    '.glide',
    '.system',
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
```

---

## Artisan commands

```bash
# Clear all Glide image cache
php artisan glide:clear

# Show Glide cache statistics
php artisan glide:stats
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
