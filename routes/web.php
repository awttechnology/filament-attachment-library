<?php

use Illuminate\Support\Facades\Route;
use AwtTechnology\FilamentAttachmentLibrary\Http\Controllers\AttachmentController;
use AwtTechnology\FilamentAttachmentLibrary\Http\Controllers\GlideController;
use AwtTechnology\FilamentAttachmentLibrary\Http\Controllers\GlidePresetController;
use AwtTechnology\FilamentAttachmentLibrary\Http\Controllers\ThumbnailController;

Route::get('files/{attachment}', AttachmentController::class)
    ->where('attachment', '.*')
    ->middleware(['web'])
    ->name('attachment');

// On-demand thumbnail resolver — keeps thumbnail generation off the browser's
// Livewire render (see ThumbnailController). Declared before the img/{options}/{path}
// catch-all below, whose unconstrained {options} would otherwise match "thumb".
// Binds by numeric id via a plain param (not model binding): Attachment overrides
// resolveRouteBinding() to look up by filename, which would 404 on an id.
Route::get('img/thumb/{id}', ThumbnailController::class)
    ->whereNumber('id')
    ->middleware(['web'])
    ->name('attachment.thumb');

Route::get('img/{preset}/{breakpoint}/{format}/{fit}/{path}', GlidePresetController::class)
    ->where(['preset' => '[a-z]+', 'breakpoint' => '\d+', 'format' => '[a-z]+', 'path' => '.*'])
    ->middleware(['web'])
    ->name('glide.preset');

Route::get('img/{options}/{path}', GlideController::class)
    ->where('path', '.*')
    ->middleware(['web'])
    ->name('glide');
