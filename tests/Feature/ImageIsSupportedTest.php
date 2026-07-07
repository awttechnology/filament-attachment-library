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

it('treats aliased extensions as their canonical format', function () {
    // gd reports JPEG (never JPG); the alias map must cover both spellings,
    // and TIF must map to TIFF the same way.
    expect(Glide::imageIsSupported('scan.jpg'))->toBeTrue()
        ->and(Glide::imageIsSupported('scan.jpeg'))->toBeTrue();

    // TIF support follows TIFF: under gd neither is supported, so both
    // spellings must agree (the old code disagreed under imagick).
    expect(Glide::imageIsSupported('scan.tif'))
        ->toBe(Glide::imageIsSupported('scan.tiff'));
});
