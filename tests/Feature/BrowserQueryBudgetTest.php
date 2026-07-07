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
