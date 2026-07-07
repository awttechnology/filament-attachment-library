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
        DB::table('attachments')->insert([
            'name' => "file-{$i}",
            'extension' => 'jpg',
            'disk' => 'attachments',
            'mime_type' => 'image/jpeg',
            'path' => null,
            'size' => 1024,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
    DB::table('attachments')->insert([
        'name' => 'file-a',
        'extension' => 'jpg',
        'disk' => 'attachments',
        'mime_type' => 'image/jpeg',
        'path' => null,
        'size' => 1024,
        'created_by' => $userId,
        'updated_by' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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
    DB::table('attachments')->insert([
        'name' => 'orphan',
        'extension' => 'jpg',
        'disk' => 'attachments',
        'mime_type' => 'image/jpeg',
        'path' => null,
        'size' => 1024,
        'created_by' => 999,
        'updated_by' => 999,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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
