<?php

use Illuminate\Support\Facades\Route;
use AwtTechnology\FilamentAttachmentLibrary\Http\Controllers\AttachmentController;
use AwtTechnology\FilamentAttachmentLibrary\Http\Controllers\GlideController;
use AwtTechnology\FilamentAttachmentLibrary\Http\Controllers\GlidePresetController;

Route::get('files/{attachment}', AttachmentController::class)
    ->where('attachment', '.*')
    ->middleware(['web'])
    ->name('attachment');

Route::get('img/{preset}/{breakpoint}/{format}/{fit}/{path}', GlidePresetController::class)
    ->where('path', '.*')
    ->middleware(['web'])
    ->name('glide.preset');

Route::get('img/{options}/{path}', GlideController::class)
    ->where('path', '.*')
    ->middleware(['web'])
    ->name('glide');
