<?php

namespace App\Services\AI;

use App\Jobs\IngestWebinarKnowledgeSourceJob;
use App\Models\WebinarAiKnowledgeSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class WebinarVideoTranscriptService
{
    public function transcribeAndQueueIngestion(WebinarAiKnowledgeSource $source): void
    {
        $videoUrl = trim((string) ($source->source_url ?? ''));
        if ($videoUrl === '') {
            throw new \RuntimeException('Missing source video URL for transcription.');
        }

        $source->update([
            'status' => 'processing',
            'error_message' => null,
            'meta' => array_merge($source->meta ?? [], [
                'transcription_status' => 'processing',
            ]),
        ]);

        $ffmpegBin = trim((string) config('services.ai_transcript.ffmpeg_bin', 'ffmpeg'));
        $ytDlpBin = trim((string) config('services.ai_transcript.yt_dlp_bin', 'yt-dlp'));
        $chunkSeconds = max(60, (int) config('services.ai_transcript.chunk_seconds', 300));
        $ffmpegTimeoutSeconds = max(120, (int) config('services.ai_transcript.ffmpeg_timeout_seconds', 7200));

        $tmpRoot = storage_path('app/tmp/webinar-ai-transcript');
        if (! is_dir($tmpRoot) && ! mkdir($tmpRoot, 0775, true) && ! is_dir($tmpRoot)) {
            throw new \RuntimeException('Failed to create temporary transcript directory.');
        }

        $workingDir = $tmpRoot.DIRECTORY_SEPARATOR.'source-'.$source->id.'-'.bin2hex(random_bytes(6));
        if (! mkdir($workingDir, 0775, true) && ! is_dir($workingDir)) {
            throw new \RuntimeException('Failed to create source temporary directory.');
        }

        try {
            $this->assertFfmpegAvailable($ffmpegBin);

            $audioPath = $workingDir.DIRECTORY_SEPARATOR.'audio_clean.wav';
            $chunkPattern = $workingDir.DIRECTORY_SEPARATOR.'chunk_%03d.wav';
            $extractInput = $videoUrl;

            // Single pass extraction + normalization gives Whisper-ready audio.
            $extractProcess = new Process([
                $ffmpegBin,
                '-y',
                '-i',
                $videoUrl,
                '-vn',
                '-ac',
                '1',
                '-ar',
                '16000',
                '-c:a',
                'pcm_s16le',
                $audioPath,
            ]);
            $extractProcess->setTimeout($ffmpegTimeoutSeconds);
            $extractProcess->run();

            if (! $extractProcess->isSuccessful()) {
                $fallbackUrl = $this->resolveStreamUrlWithYtDlp($videoUrl, $ytDlpBin);

                if ($fallbackUrl === null) {
                    throw new ProcessFailedException($extractProcess);
                }

                $extractInput = $fallbackUrl;
                $retryExtractProcess = new Process([
                    $ffmpegBin,
                    '-y',
                    '-i',
                    $extractInput,
                    '-vn',
                    '-ac',
                    '1',
                    '-ar',
                    '16000',
                    '-c:a',
                    'pcm_s16le',
                    $audioPath,
                ]);
                $retryExtractProcess->setTimeout($ffmpegTimeoutSeconds);
                $retryExtractProcess->run();

                if (! $retryExtractProcess->isSuccessful()) {
                    throw new ProcessFailedException($retryExtractProcess);
                }
            }

            $segmentProcess = new Process([
                $ffmpegBin,
                '-y',
                '-i',
                $audioPath,
                '-f',
                'segment',
                '-segment_time',
                (string) $chunkSeconds,
                '-c',
                'copy',
                $chunkPattern,
            ]);
            $segmentProcess->setTimeout($ffmpegTimeoutSeconds);
            $segmentProcess->run();

            if (! $segmentProcess->isSuccessful()) {
                throw new ProcessFailedException($segmentProcess);
            }

            $chunks = glob($workingDir.DIRECTORY_SEPARATOR.'chunk_*.wav') ?: [];
            sort($chunks);

            if ($chunks === [] && is_file($audioPath)) {
                $chunks = [$audioPath];
            }

            if ($chunks === []) {
                throw new \RuntimeException('Audio extraction succeeded but no audio chunks were produced.');
            }

            $mergedText = [];
            foreach ($chunks as $chunkPath) {
                $text = $this->transcribeChunk($chunkPath);
                if ($text !== '') {
                    $mergedText[] = $text;
                }
            }

            $transcript = trim(implode("\n\n", $mergedText));
            if ($transcript === '') {
                throw new \RuntimeException('Transcription returned empty text.');
            }

            $source->update([
                'raw_text' => $transcript,
                'status' => 'queued',
                'error_message' => null,
                'meta' => array_merge($source->meta ?? [], [
                    'transcription_status' => 'completed',
                    'video_url' => $videoUrl,
                    'ffmpeg_input_url' => $extractInput,
                    'chunk_seconds' => $chunkSeconds,
                    'transcription_chunks_count' => count($chunks),
                    'transcription_char_count' => mb_strlen($transcript),
                ]),
            ]);

            IngestWebinarKnowledgeSourceJob::dispatch($source->id)
                ->onQueue((string) config('services.queues.ai_ingest', 'ai-ingest'));
        } catch (\Throwable $e) {
            Log::warning('WebinarVideoTranscriptService failed', [
                'source_id' => $source->id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $this->deleteDirectory($workingDir);
        }
    }

    private function transcribeChunk(string $chunkPath): string
    {
        $apiKey = trim((string) config('services.openai.api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Missing OPENAI_API_KEY in environment.');
        }

        $model = trim((string) config('services.openai.transcription_model', 'whisper-1'));
        $timeout = max(30, (int) config('services.ai_transcript.openai_timeout_seconds', 180));

        $fileHandle = fopen($chunkPath, 'rb');
        if ($fileHandle === false) {
            throw new \RuntimeException('Unable to read an audio chunk for transcription.');
        }

        try {
            $response = Http::timeout($timeout)
                ->withToken($apiKey)
                ->attach('file', $fileHandle, basename($chunkPath))
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $model,
                    'response_format' => 'json',
                ]);
        } finally {
            fclose($fileHandle);
        }

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI transcription failed: HTTP '.$response->status().' - '.substr($response->body(), 0, 220));
        }

        return trim((string) data_get($response->json(), 'text', ''));
    }

    private function assertFfmpegAvailable(string $ffmpegBin): void
    {
        $process = new Process([$ffmpegBin, '-version']);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('FFmpeg is not installed or not available in PATH.');
        }
    }

    private function resolveStreamUrlWithYtDlp(string $videoUrl, string $ytDlpBin): ?string
    {
        $host = strtolower((string) parse_url($videoUrl, PHP_URL_HOST));

        if ($host === '' || ! preg_match('/youtube\.com|youtu\.be|vimeo\.com/u', $host)) {
            return null;
        }

        $versionProcess = new Process([$ytDlpBin, '--version']);
        $versionProcess->setTimeout(15);
        $versionProcess->run();

        if (! $versionProcess->isSuccessful()) {
            return null;
        }

        $process = new Process([$ytDlpBin, '-g', '--no-playlist', $videoUrl]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('yt-dlp fallback failed', [
                'video_url' => $videoUrl,
                'output' => trim($process->getErrorOutput()),
            ]);

            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($process->getOutput())) ?: [];
        foreach ($lines as $line) {
            $candidate = trim($line);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($itemPath)) {
                $this->deleteDirectory($itemPath);
                continue;
            }

            @unlink($itemPath);
        }

        @rmdir($path);
    }
}
