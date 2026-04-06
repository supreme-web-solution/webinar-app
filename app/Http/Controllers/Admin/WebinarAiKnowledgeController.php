<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IngestWebinarKnowledgeSourceJob;
use App\Models\Webinar;
use App\Models\WebinarAiKnowledgeSource;
use App\Services\AI\WebinarKnowledgeIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WebinarAiKnowledgeController extends Controller
{
    private const MAX_SOURCES_PER_WEBINAR = 3;

    public function indexSources(Request $request, Webinar $webinar): JsonResponse
    {
        $this->authorizeWebinar($webinar);

        $perPage = max(1, min((int) $request->integer('per_page', 8), 30));

        $sources = WebinarAiKnowledgeSource::query()
            ->where('webinar_id', $webinar->id)
            ->latest('id')
            ->paginate($perPage)
            ->through(function (WebinarAiKnowledgeSource $source) use ($webinar): array {
                return [
                    'id' => $source->id,
                    'type' => $source->source_type,
                    'title' => $source->title,
                    'source_url' => $source->source_url,
                    'status' => $source->status,
                    'error_message' => $source->error_message,
                    'processed_at' => $source->processed_at?->toDateTimeString(),
                    'chunk_count' => (int) data_get($source->meta, 'chunk_count', 0),
                    'chunks_url' => route('admin.webinars.ai.sources.chunks', [
                        'webinar' => $webinar->id,
                        'source' => $source->id,
                    ]),
                    'delete_url' => route('admin.webinars.ai.sources.delete', [
                        'webinar' => $webinar->id,
                        'source' => $source->id,
                    ]),
                ];
            });

        return response()->json([
            'data' => $sources->items(),
            'meta' => [
                'current_page' => $sources->currentPage(),
                'last_page' => $sources->lastPage(),
                'per_page' => $sources->perPage(),
                'total' => $sources->total(),
            ],
        ]);
    }

    public function sourceChunks(Request $request, Webinar $webinar, WebinarAiKnowledgeSource $source): JsonResponse
    {
        $this->authorizeWebinar($webinar);
        abort_unless($source->webinar_id === $webinar->id, 404);

        $perPage = max(1, min((int) $request->integer('per_page', 12), 50));

        $chunks = $source->chunks()
            ->orderBy('chunk_index')
            ->paginate($perPage)
            ->through(fn ($chunk) => [
                'id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'content' => $chunk->content,
            ]);

        return response()->json([
            'source' => [
                'id' => $source->id,
                'title' => $source->title,
                'type' => $source->source_type,
            ],
            'data' => $chunks->items(),
            'meta' => [
                'current_page' => $chunks->currentPage(),
                'last_page' => $chunks->lastPage(),
                'per_page' => $chunks->perPage(),
                'total' => $chunks->total(),
            ],
        ]);
    }

    public function storeUrl(Request $request, Webinar $webinar): RedirectResponse
    {
        $this->authorizeWebinar($webinar);
        $this->ensureSourceLimitNotReached($webinar);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $source = WebinarAiKnowledgeSource::create([
            'webinar_id' => $webinar->id,
            'source_type' => 'url',
            'title' => trim((string) ($validated['title'] ?? '')) ?: 'Website Source',
            'source_url' => (string) $validated['url'],
            'status' => 'queued',
        ]);

        IngestWebinarKnowledgeSourceJob::dispatch($source->id);

        return back()->with('success', 'URL source queued for AI knowledge ingestion.');
    }

    public function storeTranscript(Request $request, Webinar $webinar): RedirectResponse
    {
        $this->authorizeWebinar($webinar);
        $this->ensureSourceLimitNotReached($webinar);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'transcript' => ['required', 'string', 'max:200000'],
        ]);

        $source = WebinarAiKnowledgeSource::create([
            'webinar_id' => $webinar->id,
            'source_type' => 'video_transcript',
            'title' => trim((string) ($validated['title'] ?? '')) ?: 'Video Transcript',
            'raw_text' => (string) $validated['transcript'],
            'status' => 'queued',
        ]);

        IngestWebinarKnowledgeSourceJob::dispatch($source->id);

        return back()->with('success', 'Transcript queued for AI knowledge ingestion.');
    }

    public function storeFile(Request $request, Webinar $webinar, WebinarKnowledgeIngestionService $ingestionService): RedirectResponse
    {
        $this->authorizeWebinar($webinar);
        $this->ensureSourceLimitNotReached($webinar);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,txt,md,csv,xlsx,xls,docx'],
        ]);

        $file = $validated['file'];
        $storagePath = $ingestionService->storeUploadedFile($file);

        $source = WebinarAiKnowledgeSource::create([
            'webinar_id' => $webinar->id,
            'source_type' => 'file',
            'title' => trim((string) ($validated['title'] ?? '')) ?: $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'status' => 'queued',
            'meta' => [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ],
        ]);

        IngestWebinarKnowledgeSourceJob::dispatch($source->id);

        return back()->with('success', 'File source queued for AI knowledge ingestion.');
    }

    public function destroy(Webinar $webinar, WebinarAiKnowledgeSource $source): JsonResponse
    {
        $this->authorizeWebinar($webinar);
        abort_unless($source->webinar_id === $webinar->id, 404);

        $storagePath = $source->storage_path;
        $source->delete();

        if (is_string($storagePath) && $storagePath !== '') {
            Storage::delete($storagePath);
        }

        return response()->json([
            'deleted' => 1,
        ]);
    }

    public function bulkDestroy(Request $request, Webinar $webinar): JsonResponse
    {
        $this->authorizeWebinar($webinar);

        $validated = $request->validate([
            'source_ids' => ['required', 'array', 'min:1', 'max:200'],
            'source_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $sourceIds = collect($validated['source_ids'])
            ->map(fn ($id) => (int) $id)
            ->values();

        $query = WebinarAiKnowledgeSource::query()
            ->where('webinar_id', $webinar->id)
            ->whereIn('id', $sourceIds->all());

        $foundIds = (clone $query)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (count($foundIds) !== $sourceIds->count()) {
            return response()->json([
                'message' => 'One or more selected sources were not found for this webinar.',
            ], 422);
        }

        $storagePaths = (clone $query)
            ->whereNotNull('storage_path')
            ->pluck('storage_path')
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values();

        $deleted = (clone $query)->delete();

        foreach ($storagePaths as $path) {
            Storage::delete((string) $path);
        }

        return response()->json([
            'deleted' => $deleted,
        ]);
    }

    private function authorizeWebinar(Webinar $webinar): void
    {
        abort_unless($webinar->user_id === Auth::id(), 403);
    }

    private function ensureSourceLimitNotReached(Webinar $webinar): void
    {
        $count = WebinarAiKnowledgeSource::query()
            ->where('webinar_id', $webinar->id)
            ->count();

        if ($count >= self::MAX_SOURCES_PER_WEBINAR) {
            throw ValidationException::withMessages([
                'source_limit' => sprintf(
                    'You can keep up to %d sources only. Delete one and retrain to add another.',
                    self::MAX_SOURCES_PER_WEBINAR,
                ),
            ]);
        }
    }
}
