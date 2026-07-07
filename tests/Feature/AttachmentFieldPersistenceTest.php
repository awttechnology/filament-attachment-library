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

it('rehydrates the id from a stored CDN-style url that AttachmentManager::findByUrl cannot reverse', function () {
    $attachment = makeAttachment(['path' => 'brochures', 'name' => 'hero', 'extension' => 'pdf', 'mime_type' => 'application/pdf']);
    $post = TestPost::create(['featured_image_id' => 'https://cdn.example.com/brochures/hero.pdf']);

    Livewire::test(EditPostForm::class, ['post' => $post, 'storeAsUrl' => true])
        ->assertSet('data.featured_image_id', $attachment->id);
});

it('rehydrates a stale numeric id that matches no attachment to null', function () {
    $post = TestPost::create(['featured_image_id' => '999999']);

    Livewire::test(EditPostForm::class, ['post' => $post, 'storeAsUrl' => true])
        ->assertSet('data.featured_image_id', null);
});

it('dehydrates the first id of an array state to its url', function () {
    $attachment = makeAttachment();
    $post = TestPost::create();

    Livewire::test(EditPostForm::class, ['post' => $post, 'storeAsUrl' => true])
        ->set('data.featured_image_id', [$attachment->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($post->fresh()->featured_image_id)->toBe($attachment->url);
});
