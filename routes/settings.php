<?php

use App\Http\Controllers\Settings\FollowUpEmailsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SmtpController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/smtp', [SmtpController::class, 'edit'])->name('smtp.edit');
    Route::patch('settings/smtp', [SmtpController::class, 'update'])->name('smtp.update');
    Route::post('settings/smtp/test', [SmtpController::class, 'test'])->name('smtp.test');

    Route::get('settings/follow-up-emails', [FollowUpEmailsController::class, 'edit'])->name('follow-up-emails.edit');
    Route::patch('settings/follow-up-emails', [FollowUpEmailsController::class, 'update'])->name('follow-up-emails.update');
});
