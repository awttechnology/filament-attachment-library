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

it('renames unicode-named directories without corrupting descendant paths', function () {
    Storage::disk('attachments')->makeDirectory('café');
    makeAttachment(['path' => 'café', 'name' => 'a']);
    makeAttachment(['path' => 'café/x', 'name' => 'b']);

    AttachmentManager::renameDirectory('café', 'catalog');

    expect(Attachment::pluck('path')->all())
        ->toEqualCanonicalizing(['catalog', 'catalog/x']);
});
