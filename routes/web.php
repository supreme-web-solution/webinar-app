<?php

use App\Http\Controllers\Admin\WebinarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\WebinarAttendeeController;
use App\Http\Controllers\Admin\WebinarChatController as AdminWebinarChatController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\WebinarChatController;
use App\Http\Controllers\WebinarRegistrationController;
use App\Http\Controllers\WebinarRoomController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::resource('webinars', WebinarController::class)->except(['show', 'destroy']);
        Route::post('webinars/{webinar}/attendees/import', [WebinarAttendeeController::class, 'importCsv'])->name('webinars.attendees.import');
        Route::post('webinars/{webinar}/attendees/{registrant}/unsubscribe', [WebinarAttendeeController::class, 'moveToUnsubscribed'])->name('webinars.attendees.unsubscribe');
        Route::post('webinars/{webinar}/attendees/unsubscribe-bulk', [WebinarAttendeeController::class, 'moveManyToUnsubscribed'])->name('webinars.attendees.unsubscribe.bulk');
        Route::delete('webinars/{webinar}/attendees/{registrant}', [WebinarAttendeeController::class, 'deleteUnsubscribed'])->name('webinars.attendees.delete');
        Route::post('webinars/{webinar}/attendees/delete-bulk', [WebinarAttendeeController::class, 'deleteManyUnsubscribed'])->name('webinars.attendees.delete.bulk');
        Route::post('webinars/{webinar}/notify', [WebinarAttendeeController::class, 'notifyAll'])->name('webinars.notify');
        Route::get('chats', [AdminWebinarChatController::class, 'index'])->name('chats.index');
        Route::get('webinars/{webinar}/chat', [AdminWebinarChatController::class, 'show'])->name('webinars.chat.show');
        Route::post('webinars/{webinar}/chat/{registrant}', [AdminWebinarChatController::class, 'reply'])->name('webinars.chat.reply');
    });
});

Route::get('/register/{webinar:uuid}', [WebinarRegistrationController::class, 'show'])->name('webinar.register');
Route::post('/register/{webinar:uuid}', [WebinarRegistrationController::class, 'store'])->name('webinar.register.store');
Route::get('/webinar/live/{webinar:uuid}', [WebinarRoomController::class, 'showPublic'])->name('webinar.room.public');
Route::post('/webinar/live/{webinar:uuid}/access', [WebinarRegistrationController::class, 'accessFromJoinLink'])->name('webinar.room.access');
Route::get('/webinar/{token}', [WebinarRoomController::class, 'show'])->name('webinar.room');
Route::get('/webinar/{token}/chat', [WebinarChatController::class, 'index'])->name('webinar.chat.index');
Route::post('/webinar/{token}/chat', [WebinarChatController::class, 'store'])->name('webinar.chat.store');
Route::get('/unsubscribe/{token}', UnsubscribeController::class)->name('webinar.unsubscribe');

require __DIR__.'/settings.php';
