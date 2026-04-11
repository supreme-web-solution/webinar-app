<?php

use App\Http\Controllers\Admin\WebinarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\WebinarAttendeeController;
use App\Http\Controllers\Admin\WebinarChatController as AdminWebinarChatController;
use App\Http\Controllers\Admin\WebinarAiKnowledgeController;
use App\Http\Controllers\Admin\WebinarAiStudioController;
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
        // Keep show disabled, but enable destroy so hosts can remove a webinar + all related data.
        Route::resource('webinars', WebinarController::class)->except(['show']);
        Route::post('webinars/{webinar}/attendees/import', [WebinarAttendeeController::class, 'importCsv'])->name('webinars.attendees.import');
        Route::post('webinars/{webinar}/attendees/apollo-preview', [WebinarAttendeeController::class, 'previewFromApollo'])->name('webinars.attendees.apollo.preview');
        Route::post('webinars/{webinar}/attendees/apollo-fetch', [WebinarAttendeeController::class, 'fetchFromApollo'])->name('webinars.attendees.apollo.fetch');
        Route::post('webinars/{webinar}/attendees/{registrant}/unsubscribe', [WebinarAttendeeController::class, 'moveToUnsubscribed'])->name('webinars.attendees.unsubscribe');
        Route::post('webinars/{webinar}/attendees/unsubscribe-bulk', [WebinarAttendeeController::class, 'moveManyToUnsubscribed'])->name('webinars.attendees.unsubscribe.bulk');
        Route::delete('webinars/{webinar}/attendees/{registrant}', [WebinarAttendeeController::class, 'deleteUnsubscribed'])->name('webinars.attendees.delete');
        Route::post('webinars/{webinar}/attendees/delete-bulk', [WebinarAttendeeController::class, 'deleteManyUnsubscribed'])->name('webinars.attendees.delete.bulk');
        Route::post('webinars/{webinar}/notify', [WebinarAttendeeController::class, 'notifyAll'])->name('webinars.notify');
        Route::get('webinars/{webinar}/ai/sources', [WebinarAiKnowledgeController::class, 'indexSources'])->name('webinars.ai.sources.index');
        Route::get('webinars/{webinar}/ai/sources/{source}/chunks', [WebinarAiKnowledgeController::class, 'sourceChunks'])->name('webinars.ai.sources.chunks');
        Route::post('webinars/{webinar}/ai/sources/url', [WebinarAiKnowledgeController::class, 'storeUrl'])->name('webinars.ai.sources.url');
        Route::post('webinars/{webinar}/ai/sources/transcript', [WebinarAiKnowledgeController::class, 'storeTranscript'])->name('webinars.ai.sources.transcript');
        Route::post('webinars/{webinar}/ai/sources/file', [WebinarAiKnowledgeController::class, 'storeFile'])->name('webinars.ai.sources.file');
        Route::delete('webinars/{webinar}/ai/sources/{source}', [WebinarAiKnowledgeController::class, 'destroy'])->name('webinars.ai.sources.delete');
        Route::post('webinars/{webinar}/ai/sources/delete-bulk', [WebinarAiKnowledgeController::class, 'bulkDestroy'])->name('webinars.ai.sources.delete.bulk');
        Route::post('webinars/ai/script', [WebinarAiStudioController::class, 'generateScript'])->name('webinars.ai.script');
        Route::post('webinars/ai/video', [WebinarAiStudioController::class, 'generateVideo'])->name('webinars.ai.video');
        Route::get('webinars/ai/video/status', [WebinarAiStudioController::class, 'videoStatus'])->name('webinars.ai.video.status');
        Route::post('webinars/ai/create', [WebinarAiStudioController::class, 'createWebinar'])->name('webinars.ai.create');
        Route::get('chats', [AdminWebinarChatController::class, 'index'])->name('chats.index');
        Route::get('webinars/{webinar}/chat', [AdminWebinarChatController::class, 'show'])->name('webinars.chat.show');
        Route::post('webinars/{webinar}/chat/{registrant}', [AdminWebinarChatController::class, 'reply'])->name('webinars.chat.reply');
        Route::delete('webinars/{webinar}/chat/{registrant}/messages/{message}', [AdminWebinarChatController::class, 'destroyMessage'])->name('webinars.chat.message.destroy');
        Route::delete('webinars/{webinar}/chat/{registrant}/messages', [AdminWebinarChatController::class, 'destroyAllMessages'])->name('webinars.chat.messages.destroy');
    });
});

Route::get('/register/{webinar:uuid}', [WebinarRegistrationController::class, 'show'])->name('webinar.register');
Route::post('/register/{webinar:uuid}', [WebinarRegistrationController::class, 'store'])->name('webinar.register.store');
Route::get('/webinar/live/{webinar:uuid}', [WebinarRoomController::class, 'showPublic'])->name('webinar.room.public');
Route::post('/webinar/live/{webinar:uuid}/access', [WebinarRegistrationController::class, 'accessFromJoinLink'])->name('webinar.room.access');
Route::get('/webinar/{token}', [WebinarRoomController::class, 'show'])->name('webinar.room');
Route::post('/webinar/{token}/watch', [WebinarRoomController::class, 'trackWatchMilestone'])->name('webinar.watch.track');
Route::post('/webinar/{token}/offers/{offer}/click', [WebinarRoomController::class, 'trackOfferClick'])->name('webinar.offer.click');
Route::post('/webinar/{token}/cta-click', [WebinarRoomController::class, 'trackCtaClick'])->name('webinar.cta.click');
Route::get('/webinar/{token}/chat', [WebinarChatController::class, 'index'])->name('webinar.chat.index');
Route::post('/webinar/{token}/chat', [WebinarChatController::class, 'store'])->name('webinar.chat.store');
Route::get('/unsubscribe/{token}', UnsubscribeController::class)->name('webinar.unsubscribe');

require __DIR__.'/settings.php';
