<?php

namespace App\Jobs;

use App\Models\Click;
use App\Models\Conversion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Port of legacy `Component\Cleaner\DelayedCommand\DeleteStatsCommand` +
 * `Component\Cleaner\Service\CleanerService::deleteStats()` (old codebase:
 * application/Component/Cleaner/DelayedCommand/DeleteStatsCommand.php,
 * application/Component/Cleaner/Service/CleanerService.php).
 *
 * Legacy queued this via `DelayedCommandService::instance()->push()` (a
 * custom DB-backed command queue processed by a cron task); here it's a
 * plain Laravel queued job on the `database` queue connection
 * (QUEUE_CONNECTION=database, already configured) — same deferred-execution
 * semantics, native infra instead of a bespoke one.
 */
class DeleteStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly ?string $startDate = null,
        private readonly ?string $endDate = null,
        private readonly ?string $timezone = null,
        private readonly ?int $campaignId = null,
    ) {}

    public function handle(): void
    {
        $tz = empty($this->timezone) ? new \DateTimeZone('UTC') : new \DateTimeZone($this->timezone);

        $start = ! empty($this->startDate)
            ? (new \DateTime($this->startDate, $tz))->setTimezone(new \DateTimeZone('UTC'))
            : new \DateTime('1999-01-01', new \DateTimeZone('UTC'));

        $end = ! empty($this->endDate)
            ? (new \DateTime($this->endDate, $tz))->setTimezone(new \DateTimeZone('UTC'))
            : new \DateTime('2099-01-01', new \DateTimeZone('UTC'));

        $startFormatted = $start->format('Y-m-d H:i:s');
        $endFormatted = $end->format('Y-m-d H:i:s');

        $clickQuery = Click::query()->whereBetween('datetime', [$startFormatted, $endFormatted]);
        $conversionQuery = Conversion::query()->whereBetween('click_datetime', [$startFormatted, $endFormatted]);

        if ($this->campaignId !== null) {
            $clickQuery->where('campaign_id', $this->campaignId);
            $conversionQuery->where('campaign_id', $this->campaignId);
        }

        $clickQuery->delete();
        $conversionQuery->delete();
    }
}
