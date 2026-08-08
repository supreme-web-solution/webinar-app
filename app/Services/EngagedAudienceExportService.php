<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EngagedAudienceExportService
{
    /**
     * @return Builder<EmailCampaignRecipient>
     */
    public function clickedCampaignRecipientsQuery(EmailCampaign $campaign): Builder
    {
        return EmailCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('click_count', '>', 0)
            ->orderBy('email');
    }

    /**
     * @return Builder<WebinarRegistrant>
     */
    public function clickedWebinarRegistrantsQuery(Webinar $webinar): Builder
    {
        $clickedRegistrantIds = AnalyticsEvent::query()
            ->where('webinar_id', $webinar->id)
            ->whereIn('event_type', [
                'offer_cta_clicked',
                'webinar_cta_link_clicked',
                'webinar_redirect_triggered',
            ])
            ->whereNotNull('registrant_id')
            ->distinct()
            ->pluck('registrant_id');

        return WebinarRegistrant::query()
            ->where('webinar_id', $webinar->id)
            ->where(function (Builder $query) use ($clickedRegistrantIds): void {
                $query->where('has_offer_click', true);

                if ($clickedRegistrantIds->isNotEmpty()) {
                    $query->orWhereIn('id', $clickedRegistrantIds);
                }
            })
            ->orderBy('name')
            ->orderBy('email');
    }

    public function exportCampaignClickedRecipients(EmailCampaign $campaign): StreamedResponse
    {
        $query = $this->clickedCampaignRecipientsQuery($campaign);
        $slug = str($campaign->title ?: 'campaign')->slug('-');
        $filename = "campaign-{$campaign->id}-clicked-recipients-{$slug}.csv";

        return $this->streamCsv($filename, [
            'name',
            'email',
            'click_count',
            'first_clicked_at',
            'last_clicked_at',
            'is_subscribed',
        ], $query->cursor(), function (EmailCampaignRecipient $recipient): array {
            return [
                $recipient->name,
                $recipient->email,
                (string) $recipient->click_count,
                $recipient->first_clicked_at?->toDateTimeString(),
                $recipient->last_clicked_at?->toDateTimeString(),
                $recipient->is_subscribed ? 'yes' : 'no',
            ];
        });
    }

    public function exportWebinarClickedRegistrants(Webinar $webinar): StreamedResponse
    {
        $query = $this->clickedWebinarRegistrantsQuery($webinar);
        $slug = str($webinar->title ?: 'webinar')->slug('-');
        $filename = "webinar-{$webinar->id}-clicked-registrants-{$slug}.csv";

        return $this->streamCsv($filename, [
            'name',
            'email',
            'engagement_segment',
            'has_offer_click',
            'max_watch_duration_seconds',
            'registered_at',
            'is_subscribed',
        ], $query->cursor(), function (WebinarRegistrant $registrant): array {
            return [
                $registrant->name,
                $registrant->email,
                $registrant->engagement_segment,
                $registrant->has_offer_click ? 'yes' : 'no',
                (string) ($registrant->max_watch_duration_seconds ?? 0),
                $registrant->registered_at?->toDateTimeString(),
                $registrant->is_subscribed ? 'yes' : 'no',
            ];
        });
    }

    /**
     * @param  iterable<int, EmailCampaignRecipient|WebinarRegistrant>  $rows
     * @param  callable(EmailCampaignRecipient|WebinarRegistrant): array<int, string|null>  $mapRow
     */
    private function streamCsv(string $filename, array $headers, iterable $rows, callable $mapRow): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $mapRow): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $mapRow($row));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
