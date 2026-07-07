# Harness & Correctness (Phases 0–1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Testbench/Pest/PHPStan harness to this package, then fix the nine verified correctness bugs (C1–C9 in the spec) test-first — including the user-reported "selected attachment not persisted" bug.

**Architecture:** Orchestra Testbench boots the package with vendor package discovery enabled (so Filament/Livewire register themselves); a fixture Panel provider gives Filament a default panel; tests run against an in-memory SQLite schema built from the package's own migration stubs and a `Storage::fake('attachments')` disk. Each bug fix is one task: failing test → minimal fix → commit.

**Tech Stack:** PHP 8.2+, Orchestra Testbench ^10 (Laravel 12), Pest ^3, Larastan ^3, Livewire 3 test utilities, Filament 5.

**Spec:** `docs/superpowers/specs/2026-07-07-performance-reliability-design.md`

## Global Constraints

- PHP `^8.2`; package supports Laravel 11/12/13 (`illuminate/* ^11.0|^12.0|^13.0`), Filament `^5.0`, Livewire `^3.0|^4.0` — do not raise these floors.
- No public API removals; additive changes only.
- No schema changes in this plan.
- Test disk name is always `attachments` (`Storage::fake('attachments')`); a second seeded disk name `other` is used only as DB data for cross-disk assertions.
- Namespace for tests: `AwtTechnology\FilamentAttachmentLibrary\Tests\` → `tests/`.
- Every fix lands red → green: run the new test, see it fail, implement, see it pass, commit.
- All commands run from the repo root: `/home/tohir-solomons/websites/filament-attachment-library`.

---

### Task 1: Test harness (Testbench + Pest + fixtures)

**Files:**
- Modify: `composer.json`
- Create: `.gitignore`
- Create: `phpunit.xml`
- Create: `tests/TestCase.php`
- Create: `tests/Pest.php`
- Create: `tests/Fixtures/AdminPanelProvider.php`
- Create: `tests/Feature/HarnessTest.php`

**Interfaces:**
- Consumes: existing `AttachmentLibraryServiceProvider`, migration stubs in `database/migrations/*.php.stub`, `FilamentAttachmentLibrary` plugin class.
- Produces: `AwtTechnology\FilamentAttachmentLibrary\Tests\TestCase` (base class for all tests), global Pest helper `makeAttachment(array $attributes = []): Attachment`, composer scripts `composer test` / `composer analyse`. Every later task relies on these.

- [ ] **Step 1: Add dev dependencies and autoloading to composer.json**

Add these members to `composer.json` (keep all existing members untouched):

```json
{
    "require-dev": {
        "larastan/larastan": "^3.0",
        "orchestra/testbench": "^10.0",
        "pestphp/pest": "^3.7",
        "pestphp/pest-plugin-laravel": "^3.0"
    },
    "autoload-dev": {
        "psr-4": {
            "AwtTechnology\\FilamentAttachmentLibrary\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "pest",
        "analyse": "phpstan analyse"
    },
    "config": {
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    }
}
```

- [ ] **Step 2: Create .gitignore**

```gitignore
/vendor/
composer.lock
.phpunit.cache/
.phpunit.result.cache
```

- [ ] **Step 3: Install dependencies**

Run: `composer update --no-interaction`
Expected: resolves and installs testbench, pest, larastan, filament, livewire without errors. If a version conflict occurs, report the exact conflict output and stop — do not loosen the package's own `require` constraints to work around it.

- [ ] **Step 4: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 5: Create the fixture panel provider**

`tests/Fixtures/AdminPanelProvider.php` — Filament needs at least one panel to boot; the plugin is registered on it exactly as the README instructs users to.

```php
<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures;

use AwtTechnology\FilamentAttachmentLibrary\FilamentAttachmentLibrary;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(FilamentAttachmentLibrary::make());
    }
}
```

- [ ] **Step 6: Create tests/TestCase.php**

Package discovery is enabled so Filament's and Livewire's own service providers register from vendor — do NOT hand-list Filament providers. Migrations run from the package's `.php.stub` files in dependency order.

```php
<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Tests;

use AwtTechnology\FilamentAttachmentLibrary\AttachmentLibraryServiceProvider;
use AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures\AdminPanelProvider;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected $enablesPackageDiscoveries = true;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        $this->runPackageMigrations();
    }

    protected function getPackageProviders($app): array
    {
        return [
            AttachmentLibraryServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('attachment-library.disk', 'attachments');
        $app['config']->set('glide.source', 'attachments');
        $app['config']->set('glide.cache_disk', 'attachments');
    }

    protected function runPackageMigrations(): void
    {
        $stubs = [
            'create_attachments_table',
            'create_attachables_table',
            'add_collection_to_attachables_table',
            'add_order_to_attachables_table',
            'add_focal_point_to_attachments_table',
            'add_indexes_to_attachments_table',
        ];

        foreach ($stubs as $stub) {
            $migration = include __DIR__ . "/../database/migrations/{$stub}.php.stub";
            $migration->up();
        }
    }
}
```

- [ ] **Step 7: Create tests/Pest.php with the makeAttachment helper**

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;
use AwtTechnology\FilamentAttachmentLibrary\Tests\TestCase;
use Illuminate\Support\Str;

uses(TestCase::class)->in('Feature');

function makeAttachment(array $attributes = []): Attachment
{
    return Attachment::create(array_merge([
        'name'      => 'photo-' . Str::random(6),
        'extension' => 'jpg',
        'disk'      => 'attachments',
        'mime_type' => 'image/jpeg',
        'path'      => null,
        'size'      => 1024,
    ], $attributes));
}
```

- [ ] **Step 8: Write the smoke tests**

`tests/Feature/HarnessTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use Livewire\Livewire;

it('boots the package and creates attachments', function () {
    $attachment = makeAttachment(['name' => 'hero']);

    expect($attachment->filename)->toBe('hero.jpg')
        ->and($attachment->disk)->toBe('attachments');
});

it('renders the attachment browser', function () {
    makeAttachment(['name' => 'browser-smoke']);

    Livewire::test(AttachmentBrowser::class)
        ->assertHasNoErrors()
        ->assertSee('browser-smoke');
});
```

- [ ] **Step 9: Run the suite**

Run: `composer test`
Expected: 2 tests pass. If the browser render test fails with a missing Filament view/component error, read the error, check `vendor/filament/*/composer.json` `extra.laravel.providers` to confirm discovery registered them, and fix the TestCase — do not skip the test.

- [ ] **Step 10: Commit**

```bash
git add composer.json .gitignore phpunit.xml tests/
git commit -m "test: add Testbench + Pest harness with package-discovery boot"
```

---

### Task 2: PHPStan with baseline

**Files:**
- Create: `phpstan.neon`
- Create: `phpstan-baseline.neon` (generated)

**Interfaces:**
- Consumes: Task 1's composer setup (`larastan/larastan` installed, `composer analyse` script).
- Produces: `composer analyse` passing at level 5; later tasks must keep it passing for the code they touch.

- [ ] **Step 1: Create phpstan.neon**

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - phpstan-baseline.neon

parameters:
    level: 5
    paths:
        - src
```

- [ ] **Step 2: Generate the baseline for pre-existing issues**

Run: `touch phpstan-baseline.neon && vendor/bin/phpstan analyse --generate-baseline`
Expected: `[OK] Baseline generated with N errors` (N is whatever exists today — that's fine; the baseline freezes current debt so new code is held to level 5).

- [ ] **Step 3: Verify clean run**

Run: `composer analyse`
Expected: `[OK] No errors`

- [ ] **Step 4: Commit**

```bash
git add phpstan.neon phpstan-baseline.neon
git commit -m "chore: add PHPStan level 5 with baseline"
```

---

### Task 3: Browser render query budget (measurement yardstick)

**Files:**
- Create: `tests/Feature/BrowserQueryBudgetTest.php`

**Interfaces:**
- Consumes: `makeAttachment()`, `AttachmentBrowser` Livewire component.
- Produces: a guard test Phase 2 will tighten. The budget constant lives in the test file.

- [ ] **Step 1: Write the budget test**

The budget is deliberately loose (60) — today's render has known N+1s that Phase 2 removes. This test exists so Phase 2 can prove improvement by lowering the number, and so regressions past today's baseline fail loudly.

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
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

    // Phase 2 target is <= 10; today's known ceiling is ~60 (per-item work).
    expect($queries)->toBeLessThanOrEqual(60);
});
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/pest tests/Feature/BrowserQueryBudgetTest.php`
Expected: PASS. If the actual count exceeds 60, print the count, raise the budget to the observed value + 5, and note the real number in the test comment — the point is a tripwire at today's baseline, not an aspiration.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/BrowserQueryBudgetTest.php
git commit -m "test: add browser render query-budget tripwire"
```

---

### Task 4: C1 — `whereInPath` OR leaks across the disk constraint

**Files:**
- Modify: `src/AttachmentQueryBuilder.php:30-37`
- Test: `tests/Feature/WhereInPathScopeTest.php`

**Interfaces:**
- Consumes: `AttachmentQueryBuilder::whereInPath(string $path): static` (existing signature, unchanged).
- Produces: same signature, correctly grouped SQL. Tasks 5 (renameDirectory) and every `deleteDirectory` caller depend on the grouped behaviour.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/WhereInPathScopeTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

it('keeps whereInPath results inside the disk constraint', function () {
    makeAttachment(['path' => 'foo', 'name' => 'mine']);
    makeAttachment(['path' => 'foo/sub', 'name' => 'mine-sub']);
    makeAttachment(['path' => 'foo', 'name' => 'theirs', 'disk' => 'other']);

    $names = Attachment::query()
        ->whereDisk('attachments')
        ->whereInPath('foo')
        ->pluck('name');

    expect($names->all())->toEqualCanonicalizing(['mine', 'mine-sub']);
});

it('does not delete attachments on other disks when deleting a directory', function () {
    makeAttachment(['path' => 'foo', 'name' => 'mine']);
    $other = makeAttachment(['path' => 'foo', 'name' => 'theirs', 'disk' => 'other']);

    AttachmentManager::deleteDirectory('foo');

    expect(Attachment::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(Attachment::query()->where('name', 'mine')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/WhereInPathScopeTest.php`
Expected: both FAIL — the un-grouped `orWhere` compiles `disk = ? AND path = ? OR path LIKE ?`, so `theirs` leaks into results / gets deleted.

- [ ] **Step 3: Fix — group the two path conditions**

In `src/AttachmentQueryBuilder.php`, replace `whereInPath`:

```php
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
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/WhereInPathScopeTest.php`
Expected: PASS. Then run the whole suite: `composer test` — all green.

- [ ] **Step 5: Commit**

```bash
git add src/AttachmentQueryBuilder.php tests/Feature/WhereInPathScopeTest.php
git commit -m "fix: group whereInPath conditions so disk constraint applies to both"
```

---

### Task 5: C2 — `renameDirectory` corrupts paths containing the folder name twice

**Files:**
- Modify: `src/AttachmentManager.php:306-340` (`renameDirectory`)
- Test: `tests/Feature/RenameDirectoryTest.php`

**Interfaces:**
- Consumes: fixed `whereInPath` from Task 4; `AttachmentManager::renameDirectory(string $currentPath, string $newName): Directory` (signature unchanged).
- Produces: prefix-safe path rewriting, portable across SQLite/MySQL/Postgres.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/RenameDirectoryTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;
use Illuminate\Support\Facades\Storage;

it('rewrites only the leading path prefix when renaming a directory', function () {
    Storage::disk('attachments')->makeDirectory('products');
    makeAttachment(['path' => 'products', 'name' => 'a']);
    makeAttachment(['path' => 'products/x', 'name' => 'b']);
    makeAttachment(['path' => 'products/x/products', 'name' => 'c']);

    AttachmentManager::renameDirectory('products', 'catalog');

    expect(Attachment::pluck('path')->all())
        ->toEqualCanonicalizing(['catalog', 'catalog/x', 'catalog/x/products']);
});

it('does not touch sibling directories sharing the name as a prefix', function () {
    Storage::disk('attachments')->makeDirectory('products');
    makeAttachment(['path' => 'products', 'name' => 'a']);
    makeAttachment(['path' => 'products-archive', 'name' => 'keep']);

    AttachmentManager::renameDirectory('products', 'catalog');

    expect(Attachment::where('name', 'keep')->value('path'))->toBe('products-archive');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/RenameDirectoryTest.php`
Expected: first test FAILS — `REPLACE(path, 'products', 'catalog')` turns `products/x/products` into `catalog/x/catalog`. (Second may pass already; keep it as a regression guard.)

- [ ] **Step 3: Fix — prefix-safe rewrite**

In `src/AttachmentManager.php`, replace the body of `renameDirectory` from the `$ids = ...` line through the `->update(...)` call:

```php
        // Rewrite the path prefix for every affected row in a single UPDATE rather
        // than loading and saving each attachment individually. Mass updates skip
        // model events, so the id-keyed thumbnail cache is invalidated explicitly
        // (ids survive the rename; the cached URL would otherwise point at the old
        // path). Ids are captured before the update, since whereInPath no longer
        // matches afterwards.
        $ids = $this->attachmentClass::whereDisk($this->disk)->whereInPath($currentPath)->pluck('id');

        // Prefix-safe: keep everything after the old prefix and prepend the new
        // one, instead of REPLACE() which substitutes the substring anywhere in
        // the path (corrupting e.g. products/x/products).
        $connection = $this->attachmentClass::query()->getConnection();
        $quotedNewPath = $connection->getPdo()->quote($newPath);
        $remainder = 'SUBSTR(path, ' . (strlen($currentPath) + 1) . ')';
        $expression = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            ? "CONCAT({$quotedNewPath}, {$remainder})"
            : "{$quotedNewPath} || {$remainder}";

        $this->attachmentClass::whereDisk($this->disk)
            ->whereInPath($currentPath)
            ->update(['path' => $connection->raw($expression)]);
```

(The `foreach ($ids ...) Cache::forget(...)` loop and `return new Directory($newPath);` stay as they are.)

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/RenameDirectoryTest.php`
Expected: PASS. Then `composer test` — all green.

- [ ] **Step 5: Commit**

```bash
git add src/AttachmentManager.php tests/Feature/RenameDirectoryTest.php
git commit -m "fix: prefix-safe path rewrite in renameDirectory"
```

---

### Task 6: C3 — `replace()` deletes the original before the new file is written

**Files:**
- Modify: `src/AttachmentManager.php:241-262` (`replace`)
- Create: `tests/Fixtures/TestAttachmentManager.php`
- Test: `tests/Feature/ReplaceAttachmentTest.php`

**Interfaces:**
- Consumes: `AttachmentManager::replace(UploadedFile $file, Attachment $attachment): Attachment` (signature unchanged); `AttachmentManager::getFilesystem(): Filesystem` (protected hook).
- Produces: `Tests\Fixtures\TestAttachmentManager` — an `AttachmentManager` subclass with a constructor-injected `Filesystem`, reused by any test needing filesystem failure injection.

- [ ] **Step 1: Create the injectable-filesystem fixture**

`tests/Fixtures/TestAttachmentManager.php`:

```php
<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures;

use AwtTechnology\FilamentAttachmentLibrary\AttachmentManager;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * AttachmentManager with an injectable filesystem so tests can simulate
 * filesystem failures (a Storage fake cannot be made to throw).
 */
class TestAttachmentManager extends AttachmentManager
{
    public function __construct(private readonly Filesystem $filesystem)
    {
        parent::__construct();
    }

    protected function getFilesystem(): Filesystem
    {
        return $this->filesystem;
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/ReplaceAttachmentTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures\TestAttachmentManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;

it('keeps the original file when writing the replacement fails', function () {
    $attachment = makeAttachment(['path' => 'docs', 'name' => 'report', 'extension' => 'pdf', 'mime_type' => 'application/pdf']);

    $fs = Mockery::mock(Filesystem::class);
    $fs->shouldReceive('exists')->andReturn(false);
    $fs->shouldReceive('put')->once()->andThrow(new RuntimeException('write failed'));
    $fs->shouldNotReceive('delete');

    $manager = new TestAttachmentManager($fs);
    $upload = UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf');

    expect(fn () => $manager->replace($upload, $attachment))->toThrow(RuntimeException::class);
});

it('writes the replacement before deleting the original', function () {
    $attachment = makeAttachment(['path' => 'docs', 'name' => 'report', 'extension' => 'pdf', 'mime_type' => 'application/pdf']);

    $fs = Mockery::mock(Filesystem::class);
    $fs->shouldReceive('exists')->andReturn(false);
    $fs->shouldReceive('put')->once()->ordered()->andReturn(true);
    $fs->shouldReceive('delete')->once()->ordered()->andReturn(true);

    $manager = new TestAttachmentManager($fs);
    $upload = UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf');

    $manager->replace($upload, $attachment);

    expect($attachment->fresh()->name)->toBe('replacement');
});
```

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/ReplaceAttachmentTest.php`
Expected: both FAIL — current code calls `delete` before `put` (violating `shouldNotReceive('delete')` and the ordering).

- [ ] **Step 4: Fix — write first, delete after**

In `src/AttachmentManager.php`, replace the middle of `replace()` (between `$path = ...` and `$attachment->forgetCaches();`):

```php
        $oldPath = $attachment->full_path;
        if ($disk->exists($path) && $path !== $oldPath) {
            throw new DestinationAlreadyExistsException();
        }
        // Write the replacement before deleting the original so a failed write
        // never loses the existing file. A same-named replacement overwrites in
        // place and needs no delete at all.
        $disk->put($path, $file->getContent());
        if ($path !== $oldPath) {
            $disk->delete($oldPath);
        }
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/ReplaceAttachmentTest.php`
Expected: PASS. Then `composer test` — all green.

- [ ] **Step 6: Commit**

```bash
git add src/AttachmentManager.php tests/Fixtures/TestAttachmentManager.php tests/Feature/ReplaceAttachmentTest.php
git commit -m "fix: write replacement file before deleting original in replace()"
```

---

### Task 7: C9 — `move()`/`rename()` leave file and database inconsistent on DB failure

**Files:**
- Modify: `src/AttachmentManager.php:276-300` (`rename`, `move`)
- Test: `tests/Feature/MoveRenameConsistencyTest.php`

**Interfaces:**
- Consumes: `move(Attachment $file, ?string $desiredPath): void`, `rename(Attachment $file, string $name): void` (signatures unchanged).
- Produces: on DB failure the file is moved back; the exception still propagates.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/MoveRenameConsistencyTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;
use Illuminate\Support\Facades\Storage;

it('restores the file when the database update fails during move', function () {
    Storage::disk('attachments')->put('src/a.jpg', 'contents');
    $attachment = makeAttachment(['path' => 'src', 'name' => 'a']);

    Attachment::updating(function () {
        throw new RuntimeException('db down');
    });

    expect(fn () => AttachmentManager::move($attachment, 'dst'))
        ->toThrow(RuntimeException::class);

    Storage::disk('attachments')->assertExists('src/a.jpg');
    Storage::disk('attachments')->assertMissing('dst/a.jpg');
    expect($attachment->fresh()->path)->toBe('src');
});

it('restores the file when the database update fails during rename', function () {
    Storage::disk('attachments')->put('src/a.jpg', 'contents');
    $attachment = makeAttachment(['path' => 'src', 'name' => 'a']);

    Attachment::updating(function () {
        throw new RuntimeException('db down');
    });

    expect(fn () => AttachmentManager::rename($attachment, 'b'))
        ->toThrow(RuntimeException::class);

    Storage::disk('attachments')->assertExists('src/a.jpg');
    Storage::disk('attachments')->assertMissing('src/b.jpg');
    expect($attachment->fresh()->name)->toBe('a');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/MoveRenameConsistencyTest.php`
Expected: both FAIL on `assertExists('src/a.jpg')` — the file was moved but the DB update threw, leaving them disagreeing.

- [ ] **Step 3: Fix — capture original path, roll the move back on DB failure**

Replace `rename()` and `move()` in `src/AttachmentManager.php`:

```php
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
        $originalPath = $file->full_path;
        $disk->move($originalPath, $path);
        try {
            $file->update(['name' => $name]);
        } catch (\Throwable $exception) {
            // Database update failed: move the file back so filesystem and
            // database stay consistent, then rethrow.
            $disk->move($path, $originalPath);
            throw $exception;
        }
    }

    /** @throws DestinationAlreadyExistsException */
    public function move(Attachment $file, ?string $desiredPath): void
    {
        $disk = $this->getFilesystem();
        $path = "{$desiredPath}/{$file->filename}";
        if ($disk->exists($path)) {
            throw new DestinationAlreadyExistsException();
        }
        $originalPath = $file->full_path;
        $disk->move($originalPath, $path);
        try {
            $file->update(['path' => $desiredPath]);
        } catch (\Throwable $exception) {
            $disk->move($path, $originalPath);
            throw $exception;
        }
    }
```

(Note: the redundant `$file->save()` calls after `update()` are removed — `update()` already persists.)

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/MoveRenameConsistencyTest.php`
Expected: PASS. Then `composer test` — all green.

- [ ] **Step 5: Commit**

```bash
git add src/AttachmentManager.php tests/Feature/MoveRenameConsistencyTest.php
git commit -m "fix: roll back filesystem move when DB update fails in move()/rename()"
```

---

### Task 8: C7 — auto-sync race: gate is claimed after the work

**Files:**
- Modify: `src/AttachmentManager.php:127-136` (`syncIfDue`)
- Test: `tests/Feature/AutoSyncGateTest.php`

**Interfaces:**
- Consumes: protected `syncIfDue(?string $directory): void`, public `updateFiles(?string $directory): void`.
- Produces: `Cache::add` claims the gate atomically *before* syncing; the gate is released on failure so a broken sync retries.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AutoSyncGateTest.php` (fixture subclasses live inline in the test file — they are single-use):

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\AttachmentManager;
use Illuminate\Support\Facades\Cache;

class GateInspectingManager extends AttachmentManager
{
    public ?bool $gateHeldDuringSync = null;

    public function updateFiles(?string $directory): void
    {
        $this->gateHeldDuringSync = Cache::has('attachment-library-last-sync:attachments:');
        parent::updateFiles($directory);
    }
}

class FailingOnceManager extends AttachmentManager
{
    public int $calls = 0;

    public function updateFiles(?string $directory): void
    {
        $this->calls++;
        if ($this->calls === 1) {
            throw new RuntimeException('remote listing failed');
        }
        parent::updateFiles($directory);
    }
}

it('claims the sync gate before syncing so a concurrent request skips', function () {
    $manager = new GateInspectingManager();

    $manager->directories(null);

    expect($manager->gateHeldDuringSync)->toBeTrue();
});

it('releases the gate when the sync fails so the next request retries', function () {
    $manager = new FailingOnceManager();

    expect(fn () => $manager->directories(null))->toThrow(RuntimeException::class);
    $manager->directories(null);

    expect($manager->calls)->toBe(2)
        ->and(Cache::has('attachment-library-last-sync:attachments:'))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/AutoSyncGateTest.php`
Expected: the first test FAILS (`gateHeldDuringSync` is `false` — current code caches only after `updateFiles` returns, which is the race window). The second passes today; it pins the retry behaviour so the fix cannot regress it.

- [ ] **Step 3: Fix — atomic claim before work, release on failure**

Replace `syncIfDue()` in `src/AttachmentManager.php`:

```php
    protected function syncIfDue(?string $directory): void
    {
        $ttl = Config::get('attachment-library.auto_sync_interval', 300);
        $cacheKey = 'attachment-library-last-sync:' . $this->disk . ':' . ($directory ?? '');

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
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/AutoSyncGateTest.php`
Expected: both PASS. Then `composer test` — all green.

- [ ] **Step 5: Commit**

```bash
git add src/AttachmentManager.php tests/Feature/AutoSyncGateTest.php
git commit -m "fix: claim auto-sync gate atomically before syncing"
```

---

### Task 9: C5 + C8 — browser page-size fallback and disk scoping

**Files:**
- Modify: `src/Livewire/AttachmentBrowser.php:118-127` (`mount`), `:341-371` (`getDirectories`), `:376-401` (`getAttachments`)
- Test: `tests/Feature/BrowserQueryScopingTest.php`

**Interfaces:**
- Consumes: `AttachmentBrowser` Livewire component; `Config` facade already imported in the component.
- Produces: browser lists/counts only rows where `disk = config('attachment-library.disk')`; invalid `pageSize` falls back to 25.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/BrowserQueryScopingTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use Livewire\Livewire;

it('falls back to the default page size for invalid values', function () {
    Livewire::withQueryParams(['pageSize' => 999])
        ->test(AttachmentBrowser::class)
        ->assertSet('pageSize', 25);
});

it('lists only attachments from the configured disk', function () {
    makeAttachment(['name' => 'mine']);
    makeAttachment(['name' => 'foreign', 'disk' => 'other']);

    Livewire::test(AttachmentBrowser::class)
        ->assertSee('mine')
        ->assertDontSee('foreign');
});

it('counts directory items only on the configured disk', function () {
    config()->set('attachment-library.directory_source', 'database');
    makeAttachment(['path' => 'docs', 'name' => 'a']);
    makeAttachment(['path' => 'docs', 'name' => 'b', 'disk' => 'other']);

    $directories = Livewire::test(AttachmentBrowser::class)->viewData('directories');

    expect($directories)->toHaveCount(1)
        ->and($directories->first()->itemCount())->toBe(1);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/BrowserQueryScopingTest.php`
Expected: all three FAIL (pageSize becomes 1; `foreign` renders; count is 2).

- [ ] **Step 3: Fix mount() fallback**

In `mount()`, change:

```php
        if (!in_array($this->pageSize, self::PAGE_SIZES)) {
            $this->pageSize = 25;
        }
```

- [ ] **Step 4: Fix disk scoping in both queries**

In `getDirectories()`, change the counts query to:

```php
        // Resolve every directory's item count in a single grouped query instead
        // of one COUNT per directory (DirectoryViewModel::itemCount()).
        $counts = Attachment::query()
            ->where('disk', Config::get('attachment-library.disk'))
            ->whereIn('path', $directories->map(fn (Directory $directory) => $directory->fullPath)->all())
            ->groupBy('path')
            ->selectRaw('path, count(*) as aggregate')
            ->pluck('aggregate', 'path');
```

In `getAttachments()`, add the disk constraint as the first builder call:

```php
        $attachments = Attachment::query()
            ->where('disk', Config::get('attachment-library.disk'))
            ->when($this->search, function (Builder $query) {
                $query->where('name', 'like', '%' . $this->search . '%');
            })
```

(rest of the chain unchanged).

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/BrowserQueryScopingTest.php`
Expected: PASS. Then `composer test` — all green.

- [ ] **Step 6: Commit**

```bash
git add src/Livewire/AttachmentBrowser.php tests/Feature/BrowserQueryScopingTest.php
git commit -m "fix: sane pageSize fallback and disk scoping in attachment browser"
```

---

### Task 10: C6 — one broken image breaks the whole browser page

**Files:**
- Modify: `src/ViewModels/AttachmentViewModel.php:124-136` (`thumbnailUrl`)
- Test: `tests/Feature/ThumbnailFallbackTest.php`

**Interfaces:**
- Consumes: `Glide` and `Resizer` facades (`AwtTechnology\FilamentAttachmentLibrary\Facades\`); `AttachmentViewModel::thumbnailUrl(): ?string` (signature unchanged).
- Produces: `thumbnailUrl()` never throws; on failure it reports the exception and returns the original file URL.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ThumbnailFallbackTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Facades\Glide;
use AwtTechnology\FilamentAttachmentLibrary\Facades\Resizer;
use AwtTechnology\FilamentAttachmentLibrary\ViewModels\AttachmentViewModel;

it('falls back to the original url when thumbnail generation throws', function () {
    $attachment = makeAttachment();

    Glide::shouldReceive('imageIsSupported')->andReturn(true);
    Resizer::shouldReceive('src')->andThrow(new RuntimeException('corrupt image'));

    $viewModel = new AttachmentViewModel($attachment);

    expect($viewModel->thumbnailUrl())->toBe($attachment->url);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/ThumbnailFallbackTest.php`
Expected: FAIL — the `RuntimeException` escapes `thumbnailUrl()`.

- [ ] **Step 3: Fix — catch, report, fall back**

Replace `thumbnailUrl()` in `src/ViewModels/AttachmentViewModel.php`:

```php
    public function thumbnailUrl(): ?string
    {
        return Cache::remember(
            'attachment-thumbnail-url:' . $this->id . ':h320',
            now()->addDay(),
            function () {
                try {
                    if (!Glide::imageIsSupported($this->attachment->full_path)) {
                        return $this->attachment->url;
                    }
                    return Resizer::src($this->attachment)->height(320)->resize()['url'] ?? null;
                } catch (\Throwable $exception) {
                    // A single unreadable image must degrade to its original URL,
                    // not take down the whole browser page. The fallback is cached
                    // like a real thumbnail so a broken file is not retried per
                    // render; replacing the file clears the key via forgetCaches().
                    report($exception);
                    return $this->attachment->url;
                }
            }
        );
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/ThumbnailFallbackTest.php`
Expected: PASS. Then `composer test` — all green.

- [ ] **Step 5: Commit**

```bash
git add src/ViewModels/AttachmentViewModel.php tests/Feature/ThumbnailFallbackTest.php
git commit -m "fix: degrade to original URL when thumbnail generation fails"
```

---

### Task 11: C4a — modal state wiring (openModal / closeModal)

**Files:**
- Modify: `src/Livewire/AttachmentBrowser.php:404-437` (`closeModal`, `openModal`)
- Test: `tests/Feature/BrowserModalStateTest.php`

**Interfaces:**
- Consumes: `AttachmentBrowser::openModal(?string $statePath = null, int|array|null $selected = null, ?bool $multiple = null, ?string $mime = null, ?bool $disableMimeFilter = null, ?string $directory = null): void`, `closeModal(bool $save = false): void` (signatures unchanged).
- Produces: null-safe boolean assignment; selection cleared on every open; `closeModal` resets only modal-scoped state (`selected`, `statePath`, `multiple`, `mime`, `disableMimeFilter`, `search`) — `basePath`, `currentPath`, `layout`, `sortBy`, `pageSize` survive. Task 12's field round-trip relies on the dispatched event contract: event name `attachments-selected-{md5(statePath)}`, payload `statePath` + `selected` (scalar id for single-select, array for multi).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/BrowserModalStateTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Livewire\AttachmentBrowser;
use Livewire\Livewire;

it('opens the modal without a multiple flag without crashing', function () {
    Livewire::test(AttachmentBrowser::class)
        ->call('openModal', 'some.path', 5, null, null, null, null)
        ->assertSet('multiple', false)
        ->assertSet('selected', [5]);
});

it('clears the previous selection when reopening for another field', function () {
    Livewire::test(AttachmentBrowser::class)
        ->call('openModal', 'field.a', 5, false)
        ->call('closeModal', false)
        ->call('openModal', 'field.b', null, false)
        ->assertSet('selected', []);
});

it('preserves basePath and browsing state across close', function () {
    Livewire::test(AttachmentBrowser::class, ['basePath' => 'media'])
        ->call('openModal', 'field.a', null, false, null, false, 'images')
        ->call('closeModal', false)
        ->assertSet('basePath', 'media');
});

it('dispatches the selected id as a scalar on save-close for single select', function () {
    $attachment = makeAttachment();

    Livewire::test(AttachmentBrowser::class)
        ->call('openModal', 'data.featured_image_id', null, false)
        ->call('selectAttachment', $attachment->id)
        ->call('closeModal', true)
        ->assertDispatched(
            'attachments-selected-' . md5('data.featured_image_id'),
            statePath: 'data.featured_image_id',
            selected: $attachment->id,
        );
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/BrowserModalStateTest.php`
Expected: FAILURES — test 1 throws `TypeError` (null into `bool $multiple`); test 2 leaves `[5]` selected (or fails earlier); test 3 loses `basePath` to the blanket `$this->reset()`.

- [ ] **Step 3: Fix openModal and closeModal**

Replace both methods in `src/Livewire/AttachmentBrowser.php`:

```php
    #[On('close-modal')]
    public function closeModal(bool $save = false): void
    {
        if ($save) {
            $selected = match ($this->multiple) {
                true => $this->selected,
                false => $this->selected[0] ?? null,
            };

            // Fire a dynamic event name based on the statePath so only the correct listener picks it up
            $this->dispatch('attachments-selected-' . md5($this->statePath), statePath: $this->statePath, selected: $selected);
        }

        $this->dispatch('highlight-attachment', null);

        // Reset only modal-scoped state. A blanket reset() also wiped basePath,
        // currentPath, layout and the forms, breaking the next open of the modal.
        $this->reset(['selected', 'statePath', 'multiple', 'mime', 'disableMimeFilter', 'search']);
    }

    #[On('open-attachment-modal')]
    public function openModal(?string $statePath = null, int|array|null $selected = null, ?bool $multiple = null, ?string $mime = null, ?bool $disableMimeFilter = null, ?string $directory = null): void
    {
        $this->statePath = $statePath;
        $this->multiple = $multiple ?? false;
        $this->mime = $mime;
        $this->disableMimeFilter = $disableMimeFilter ?? false;

        if ($directory !== null) {
            $this->currentPath = $this->normalizePath($directory);
        }

        // Always replace the selection: reopening for a field with no value must
        // not inherit the previous field's selection.
        $this->selected = collect(is_array($selected) ? $selected : [$selected])
            ->filter()
            ->values()
            ->all();
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/BrowserModalStateTest.php`
Expected: all PASS. Then `composer test` — all green (the harness smoke test exercises render; modal tests exercise state).

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/AttachmentBrowser.php tests/Feature/BrowserModalStateTest.php
git commit -m "fix: null-safe openModal and targeted state reset in closeModal"
```

---

### Task 12: C4b — field save round-trip and first-class URL storage (`storeAsUrl`)

**Files:**
- Modify: `src/Forms/Components/AttachmentField.php` (add `storeAsUrl()`)
- Modify: `README.md:226-252` (replace the fragile `dehydrateStateUsing`/`formatStateUsing` recipe)
- Create: `tests/Fixtures/TestPost.php`
- Create: `tests/Fixtures/EditPostForm.php`
- Create: `tests/Fixtures/views/edit-post-form.blade.php`
- Test: `tests/Feature/AttachmentFieldPersistenceTest.php`

**Interfaces:**
- Consumes: Task 11's event contract; `AttachmentManager::findByUrl(string $url): ?Attachment` (facade — handles local route-prefix stripping, unlike the model's `whereUrl`); `AttachmentField` (existing component).
- Produces: `AttachmentField::storeAsUrl(): static` — single-select only: dehydrates the state id to the attachment's public URL, rehydrates a stored URL back to the id via the indexed lookup.

- [ ] **Step 1: Create the fixture model**

`tests/Fixtures/TestPost.php`:

```php
<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class TestPost extends Model
{
    protected $table = 'posts';

    protected $guarded = [];
}
```

- [ ] **Step 2: Create the fixture form component and view**

`tests/Fixtures/EditPostForm.php`:

```php
<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures;

use AwtTechnology\FilamentAttachmentLibrary\Forms\Components\AttachmentField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * Minimal Filament form hosting an AttachmentField, mirroring the README's
 * "store attachment ID in a model column" usage.
 */
class EditPostForm extends Component implements HasForms
{
    use InteractsWithForms;

    public ?array $data = [];

    public TestPost $post;

    public bool $storeAsUrl = false;

    public function mount(TestPost $post, bool $storeAsUrl = false): void
    {
        $this->post = $post;
        $this->storeAsUrl = $storeAsUrl;
        $this->form->fill($this->post->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        $field = AttachmentField::make('featured_image_id');

        if ($this->storeAsUrl) {
            $field->storeAsUrl();
        }

        return $schema->components([$field])
            ->statePath('data')
            ->model($this->post);
    }

    public function save(): void
    {
        $this->post->update($this->form->getState());
    }

    public function render()
    {
        return view('edit-post-form');
    }
}
```

`tests/Fixtures/views/edit-post-form.blade.php`:

```blade
<div>
    <form wire:submit="save">
        {{ $this->form }}
    </form>
</div>
```

Register the fixture view location — add this line at the end of `setUp()` in `tests/TestCase.php`:

```php
        $this->app['view']->addLocation(__DIR__ . '/Fixtures/views');
```

- [ ] **Step 3: Write the failing tests**

`tests/Feature/AttachmentFieldPersistenceTest.php`:

```php
<?php

use AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures\EditPostForm;
use AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures\TestPost;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    Schema::create('posts', function (Blueprint $table) {
        $table->id();
        // string column so the same table serves both id- and url-storage tests
        $table->string('featured_image_id')->nullable();
        $table->timestamps();
    });
});

it('persists a picked attachment id through a form save', function () {
    $attachment = makeAttachment();
    $post = TestPost::create();

    Livewire::test(EditPostForm::class, ['post' => $post])
        ->set('data.featured_image_id', $attachment->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($post->fresh()->featured_image_id)->toBe((string) $attachment->id);
});

it('rehydrates the saved attachment when the form reopens', function () {
    $attachment = makeAttachment();
    $post = TestPost::create(['featured_image_id' => $attachment->id]);

    Livewire::test(EditPostForm::class, ['post' => $post])
        ->assertSet('data.featured_image_id', (string) $attachment->id);
});

it('stores the attachment url when storeAsUrl is enabled', function () {
    $attachment = makeAttachment();
    $post = TestPost::create();

    Livewire::test(EditPostForm::class, ['post' => $post, 'storeAsUrl' => true])
        ->set('data.featured_image_id', $attachment->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($post->fresh()->featured_image_id)->toBe($attachment->url);
});

it('rehydrates the attachment id from a stored url', function () {
    $attachment = makeAttachment();
    $post = TestPost::create(['featured_image_id' => $attachment->url]);

    Livewire::test(EditPostForm::class, ['post' => $post, 'storeAsUrl' => true])
        ->assertSet('data.featured_image_id', $attachment->id);
});
```

- [ ] **Step 4: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/AttachmentFieldPersistenceTest.php`
Expected: tests 1–2 should PASS (they pin the plain-id contract — if either fails, that failure IS the user's bug: debug the actual break in dehydration before moving on, and record what you find in the commit message). Tests 3–4 FAIL with "Method storeAsUrl does not exist".

- [ ] **Step 5: Implement storeAsUrl()**

In `src/Forms/Components/AttachmentField.php`, add these imports at the top:

```php
use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
```

and add this method (after `relationship()`):

```php
    /**
     * Store the attachment's public URL in the model column instead of its id.
     *
     * Single-select fields only. Replaces the fragile manual
     * dehydrateStateUsing/formatStateUsing recipe: rehydration uses the indexed
     * AttachmentManager::findByUrl() lookup instead of loading the whole table.
     */
    public function storeAsUrl(): static
    {
        $this->dehydrateStateUsing(function ($state) {
            $id = $state instanceof Collection ? $state->first() : $state;

            return blank($id) ? null : Attachment::find($id)?->url;
        });

        $this->formatStateUsing(function ($state) {
            if (blank($state)) {
                return null;
            }
            if (is_numeric($state)) {
                return (int) $state;
            }

            return AttachmentManager::findByUrl($state)?->id;
        });

        return $this;
    }
```

(`Collection` and `Attachment` are already imported in this file.)

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Feature/AttachmentFieldPersistenceTest.php`
Expected: all four PASS. Then `composer test` — all green.

- [ ] **Step 7: Update the README recipe**

In `README.md`, replace the "Syncing to an AttachmentField" example's field definition (the block using `dehydrateStateUsing`/`formatStateUsing` with `Attachment::get()->first(...)`) with:

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

and add directly below it:

```markdown
> `storeAsUrl()` stores the attachment's public URL in the column and resolves it
> back to the attachment on load via an indexed lookup. Single-select fields only.
> It replaces the previous `dehydrateStateUsing`/`formatStateUsing` recipe, which
> loaded the entire attachments table and silently produced `null` on any URL
> mismatch (the field then appeared to "lose" its value).
```

- [ ] **Step 8: Commit**

```bash
git add src/Forms/Components/AttachmentField.php tests/ README.md
git commit -m "feat: storeAsUrl() on AttachmentField + field persistence round-trip tests"
```

---

### Task 13: Final verification

**Files:**
- Modify: `README.md` (add a Testing section)

**Interfaces:**
- Consumes: everything above.
- Produces: green suite + analysis, documented test commands.

- [ ] **Step 1: Run the full suite and static analysis**

Run: `composer test && composer analyse`
Expected: every test passes; PHPStan reports no new errors. If PHPStan flags code added by Tasks 4–12, fix the flagged code (do not add it to the baseline).

- [ ] **Step 2: Add a Testing section to README.md**

Append before the "Extending" section:

```markdown
---

## Testing

```bash
composer test      # Pest test suite (Orchestra Testbench)
composer analyse   # PHPStan level 5
```
```

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document test and analysis commands"
```

- [ ] **Step 4: Check the spec's Phase 1 exit criteria**

All of C1–C9 from `docs/superpowers/specs/2026-07-07-performance-reliability-design.md` now have a test and a fix. Phases 2 (browser hot path) and 3 (async pipeline) are separate plans — do not start them here.
