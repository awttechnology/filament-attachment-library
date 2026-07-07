<?php

use AwtTechnology\FilamentAttachmentLibrary\Facades\AttachmentManager;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

it('keeps whereInPath results inside the disk constraint', function () {
    makeAttachment(['path' => 'foo', 'name' => 'mine']);
    makeAttachment(['path' => 'foo/sub', 'name' => 'mine-sub']);
    makeAttachment(['path' => 'foo/sub', 'name' => 'theirs-sub', 'disk' => 'other']);

    $names = Attachment::query()
        ->whereDisk('attachments')
        ->whereInPath('foo')
        ->pluck('name');

    expect($names->all())->toEqualCanonicalizing(['mine', 'mine-sub']);
});

it('does not delete attachments on other disks when deleting a directory', function () {
    makeAttachment(['path' => 'foo', 'name' => 'mine']);
    $other = makeAttachment(['path' => 'foo/sub', 'name' => 'theirs-sub', 'disk' => 'other']);

    AttachmentManager::deleteDirectory('foo');

    expect(Attachment::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(Attachment::query()->where('name', 'mine')->exists())->toBeFalse();
});
