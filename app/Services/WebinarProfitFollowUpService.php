<?php

namespace App\Services;

use App\Jobs\SendWebinarEmailsBatchJob;
use App\Models\AnalyticsEvent;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use App\Models\WebinarView;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class WebinarProfitFollowUpService
{
    /**
     * @return array{total_sent:int, webinars_processed:int, below_50_sent:int, above_50_sent:int, completed_no_click_sent:int}
     */
    public function dispatchDueSegmentedFollowUps(): array
    {
        $now = Carbon::now();
        $totals = [
            'total_sent' => 0,
            'webinars_processed' => 0,
            'below_50_sent' => 0,
            'above_50_sent' => 0,
            'completed_no_click_sent' => 0,
        ];

        $webinars = Webinar::query()
            ->where('is_published', true)
            ->where('schedule_mode', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->get();

        foreach ($webinars as $webinar) {
            $endAt = $webinar->scheduledEndAt();

            if ($endAt === null || $now->lessThan($endAt)) {
                continue;
            }

            if (! (bool) data_get($webinar->email_settings, 'send_follow_up', true)) {
                continue;
            }

            if (! (bool) data_get($webinar->email_settings, 'auto_follow_up_profit_enabled', true)) {
                continue;
            }

            $result = $this->dispatchForWebinar($webinar);

            $totals['webinars_processed']++;
            $totals['total_sent'] += $result['total_sent'];
            $totals['below_50_sent'] += $result['below_50_sent'];
            $totals['above_50_sent'] += $result['above_50_sent'];
            $totals['completed_no_click_sent'] += $result['completed_no_click_sent'];
        }

        return $totals;
    }

    /**
     * @return array{total_sent:int, below_50_sent:int, above_50_sent:int, completed_no_click_sent:int}
     */
    public function dispatchForWebinar(Webinar $webinar): array
    {
        $this->syncRegistrantEngagementState($webinar);

        $webinar->loadMissing('user');
        $owner = $webinar->user;

        $offerUrls = $this->extractUniqueOfferUrls($webinar);

        $belowCfg = $this->readSegmentConfig($owner, 'below_50');
        $aboveCfg = $this->readSegmentConfig($owner, 'above_50');
        $completedCfg = $this->readSegmentConfig($owner, 'completed_no_click');

        $below50Ids = WebinarRegistrant::query()
            ->where('webinar_id', $webinar->id)
            ->where('is_subscribed', true)
            ->where('engagement_segment', 'below_50')
            ->whereNull('follow_up_lt_50_sent_at')
            ->pluck('id');

        $above50Ids = WebinarRegistrant::query()
            ->where('webinar_id', $webinar->id)
            ->where('is_subscribed', true)
            ->where('engagement_segment', 'above_50')
            ->whereNull('follow_up_gte_50_sent_at')
            ->pluck('id');

        $completedNoClickIds = WebinarRegistrant::query()
            ->where('webinar_id', $webinar->id)
            ->where('is_subscribed', true)
            ->where('engagement_segment', 'completed_no_click')
            ->whereNull('follow_up_completed_no_click_sent_at')
            ->pluck('id');

        $below50Sent = $belowCfg['enabled']
            ? $this->dispatchBatches(
                $webinar,
                $below50Ids,
                $this->resolveSegmentSubject('below_50', $belowCfg['subject']),
                $this->composeSegmentIntro('below_50', $belowCfg['body'], $offerUrls),
                'follow_up_lt_50_sent_at',
            )
            : 0;

        $above50Sent = $aboveCfg['enabled']
            ? $this->dispatchBatches(
                $webinar,
                $above50Ids,
                $this->resolveSegmentSubject('above_50', $aboveCfg['subject']),
                $this->composeSegmentIntro('above_50', $aboveCfg['body'], $offerUrls),
                'follow_up_gte_50_sent_at',
            )
            : 0;

        $completedNoClickSent = $completedCfg['enabled']
            ? $this->dispatchBatches(
                $webinar,
                $completedNoClickIds,
                $this->resolveSegmentSubject('completed_no_click', $completedCfg['subject']),
                $this->composeSegmentIntro('completed_no_click', $completedCfg['body'], $offerUrls),
                'follow_up_completed_no_click_sent_at',
            )
            : 0;

        $totalSent = $below50Sent + $above50Sent + $completedNoClickSent;

        Log::info('webinar_profit_follow_up.dispatched', [
            'webinar_id' => $webinar->id,
            'below_50_sent' => $below50Sent,
            'above_50_sent' => $above50Sent,
            'completed_no_click_sent' => $completedNoClickSent,
            'total_sent' => $totalSent,
        ]);

        return [
            'total_sent' => $totalSent,
            'below_50_sent' => $below50Sent,
            'above_50_sent' => $above50Sent,
            'completed_no_click_sent' => $completedNoClickSent,
        ];
    }

    private function syncRegistrantEngagementState(Webinar $webinar): void
    {
        $halfwayThreshold = $this->halfwayThresholdSeconds($webinar);

        $maxWatchByRegistrant = WebinarView::query()
            ->where('webinar_id', $webinar->id)
            ->whereNotNull('registrant_id')
            ->selectRaw('registrant_id, MAX(watch_duration_seconds) as max_watch_seconds')
            ->groupBy('registrant_id')
            ->pluck('max_watch_seconds', 'registrant_id');

        $clickedRegistrantIds = AnalyticsEvent::query()
            ->where('webinar_id', $webinar->id)
            ->whereNotNull('registrant_id')
            ->whereIn('event_type', ['offer_cta_clicked', 'webinar_cta_link_clicked', 'webinar_redirect_triggered'])
            ->pluck('registrant_id')
            ->unique()
            ->values()
            ->flip();

        $watchedToEndIds = AnalyticsEvent::query()
            ->where('webinar_id', $webinar->id)
            ->whereNotNull('registrant_id')
            ->where('event_type', 'webinar_watched_to_end')
            ->pluck('registrant_id')
            ->unique()
            ->values()
            ->flip();

        WebinarRegistrant::query()
            ->where('webinar_id', $webinar->id)
            ->select([
                'id',
                'max_watch_duration_seconds',
                'has_reached_50_percent',
                'has_watched_to_end',
                'has_offer_click',
                'engagement_segment',
            ])
            ->chunkById(200, function (Collection $registrants) use ($maxWatchByRegistrant, $clickedRegistrantIds, $watchedToEndIds, $halfwayThreshold): void {
                $now = Carbon::now();

                foreach ($registrants as $registrant) {
                    $aggregatedWatch = (int) ($maxWatchByRegistrant[$registrant->id] ?? 0);
                    $maxWatch = max((int) $registrant->max_watch_duration_seconds, $aggregatedWatch);

                    $hasClick = (bool) ($registrant->has_offer_click || isset($clickedRegistrantIds[$registrant->id]));
                    $hasWatchedToEnd = (bool) ($registrant->has_watched_to_end || isset($watchedToEndIds[$registrant->id]));
                    $hasReached50 = (bool) ($registrant->has_reached_50_percent || $maxWatch >= $halfwayThreshold || $hasWatchedToEnd);

                    $segment = $this->resolveSegment($hasReached50, $hasWatchedToEnd, $hasClick);

                    $registrant->forceFill([
                        'max_watch_duration_seconds' => $maxWatch,
                        'has_reached_50_percent' => $hasReached50,
                        'has_watched_to_end' => $hasWatchedToEnd,
                        'has_offer_click' => $hasClick,
                        'engagement_segment' => $segment,
                        'engagement_segment_updated_at' => $now,
                    ])->save();
                }
            });
    }

    /**
     * @return array<int, string>
     */
    private function extractUniqueOfferUrls(Webinar $webinar): array
    {
        $webinar->loadMissing([
            'offers' => fn ($query) => $query->where('is_active', true)->orderBy('trigger_second'),
        ]);

        $urls = [];

        foreach ($webinar->offers as $offer) {
            $normalized = $this->normalizeUrl((string) $offer->button_url);
            if ($normalized !== null) {
                $urls[] = $normalized;
            }
        }

        $playbackUrls = [
            (string) data_get($webinar->playback_settings, 'redirect_url', ''),
            (string) data_get($webinar->playback_settings, 'exit_popup_cta_url', ''),
        ];

        foreach ($playbackUrls as $url) {
            $normalized = $this->normalizeUrl($url);
            if ($normalized !== null) {
                $urls[] = $normalized;
            }
        }

        return array_values(array_unique($urls));
    }

    private function normalizeUrl(string $rawUrl): ?string
    {
        $trimmed = trim($rawUrl);
        if ($trimmed === '') {
            return null;
        }

        if (! filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * @return array{enabled: bool, subject: string, body: string}
     */
    private function readSegmentConfig(?User $user, string $segment): array
    {
        $row = data_get($user?->follow_up_segment_emails, $segment, []);

        return [
            'enabled' => (bool) data_get($row, 'enabled', true),
            'subject' => trim((string) data_get($row, 'subject', '')),
            'body' => trim((string) data_get($row, 'body', '')),
        ];
    }

    private function resolveSegmentSubject(string $segment, string $customSubject): string
    {
        if ($customSubject !== '') {
            return $customSubject;
        }

        return match ($segment) {
            'completed_no_click' => 'Webinar Follow-up: You Watched It All - Claim Your Offer',
            'above_50' => 'Webinar Follow-up: Continue Where You Stopped',
            default => 'Webinar Follow-up: Quick Recap + Best Offer',
        };
    }

    private function defaultSegmentBody(string $segment): string
    {
        return match ($segment) {
            'completed_no_click' => 'You watched the full webinar, but did not click any offer. We saved the strongest offer links for you here so you can take action now.',
            'above_50' => 'You watched more than half of the webinar. Here are the most relevant offer links so you can continue where you stopped.',
            default => 'You watched less than half of the webinar. Here is a quick path to the core offer links so you can still get the result without rewatching everything.',
        };
    }

    /**
     * @param  array<int, string>  $offerUrls
     */
    private function formatOfferLinksPlainBlock(array $offerUrls): string
    {
        $offerLines = [];
        foreach ($offerUrls as $url) {
            $offerLines[] = "- {$url}";
        }

        if ($offerLines === []) {
            return '';
        }

        return "\n\nOffer links:\n".implode("\n", $offerLines);
    }

    /**
     * @param  array<int, string>  $offerUrls
     */
    private function formatOfferLinksHtmlBlock(array $offerUrls): string
    {
        if ($offerUrls === []) {
            return '';
        }

        $items = '';
        foreach ($offerUrls as $url) {
            $safe = e($url);
            $items .= '<li style="margin:0 0 8px 0;"><a href="'.$safe.'" style="color:#2563eb;text-decoration:underline;word-break:break-all;">'.$safe.'</a></li>';
        }

        return '<p style="margin:16px 0 8px 0;font-weight:700;color:#111827;">Offer links</p>'
            .'<ul style="margin:0 0 0 18px;padding:0;color:#374151;font-size:15px;line-height:1.6;">'.$items.'</ul>';
    }

    private function looksLikeHtml(string $value): bool
    {
        return (bool) preg_match('/<(p|div|br|ul|ol|li|strong|em|b|i|u|h[1-3]|a|blockquote)\b/i', $value);
    }

    /**
     * @param  array<int, string>  $offerUrls
     */
    private function composeSegmentIntro(string $segment, string $customBody, array $offerUrls): string
    {
        $usesCustom = $customBody !== '';
        $base = $usesCustom ? $customBody : $this->defaultSegmentBody($segment);
        $baseIsHtml = $usesCustom && $this->looksLikeHtml($base);

        $offerPlain = $this->formatOfferLinksPlainBlock($offerUrls);
        $offerHtml = $this->formatOfferLinksHtmlBlock($offerUrls);

        if (str_contains($base, '{{offer_links}}')) {
            $replacement = $baseIsHtml ? $offerHtml : ltrim($offerPlain);

            return str_replace('{{offer_links}}', $replacement, $base);
        }

        return $base.($baseIsHtml ? $offerHtml : $offerPlain);
    }

    private function halfwayThresholdSeconds(Webinar $webinar): int
    {
        $durationSeconds = max(1, (int) ($webinar->video_duration_seconds ?? 5400));

        return max(1, (int) floor($durationSeconds * 0.5) + 1);
    }

    private function resolveSegment(bool $hasReached50, bool $hasWatchedToEnd, bool $hasOfferClick): string
    {
        if ($hasWatchedToEnd && ! $hasOfferClick) {
            return 'completed_no_click';
        }

        if ($hasWatchedToEnd && $hasOfferClick) {
            return 'completed_clicked';
        }

        if ($hasReached50) {
            return 'above_50';
        }

        return 'below_50';
    }

    /**
     * @param  Collection<int, int>  $registrantIds
     */
    private function dispatchBatches(
        Webinar $webinar,
        Collection $registrantIds,
        string $subject,
        string $intro,
        string $markSentColumn
    ): int {
        $batchSize = max(1, (int) env('WEBINAR_EMAIL_BATCH_SIZE', 100));
        $baseDelaySeconds = max(0, (int) env('WEBINAR_EMAIL_BATCH_DELAY_BASE_SECONDS', 0));
        $delayIncrementSeconds = max(0, (int) env('WEBINAR_EMAIL_BATCH_DELAY_INCREMENT_SECONDS', 5));
        $emailQueue = (string) config('services.queues.emails', 'emails');

        $chunks = $registrantIds
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->chunk($batchSize);

        foreach ($chunks as $index => $chunk) {
            $delaySeconds = $baseDelaySeconds + ((int) $index * $delayIncrementSeconds);

            SendWebinarEmailsBatchJob::dispatch(
                $webinar->id,
                $chunk->all(),
                $subject,
                $intro,
                $markSentColumn,
            )
                ->onQueue($emailQueue)
                ->delay(now()->addSeconds($delaySeconds));
        }

        return $chunks->sum(fn ($chunk) => $chunk->count());
    }
}
