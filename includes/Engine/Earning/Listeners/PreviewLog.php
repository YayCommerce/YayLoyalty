<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Points\Result;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/** The debug lines every listener writes, for a preview (staging) or an award Outcome, so the three event families read the same in the log. */
final class PreviewLog {

    /**
     * @param string $source  The listener, as the log line's prefix.
     * @param string $subject What was evaluated, e.g. "order #12 stage" or "user #7 signup".
     */
    public static function write( string $source, string $subject, Result $result ): void {
        $campaign = $result->get_campaign();

        Log::debug(
            $source,
            sprintf(
                '%s preview: campaign=%s points=%d reason=%s',
                $subject,
                $campaign ? $campaign->get_id() : 'none',
                $result->get_points(),
                $result->get_reason() ? $result->get_reason() : 'awarded'
            )
        );
    }

    /**
     * The award line: status, points written and the skip reason, if any.
     *
     * @param string $source  The listener, as the log line's prefix.
     * @param string $subject What was awarded, e.g. "order #12 purchase" or "user #7 signup".
     */
    public static function write_outcome( string $source, string $subject, Outcome $outcome ): void {
        $entry = $outcome->get_entry();

        Log::debug(
            $source,
            sprintf(
                '%s award: %s points=%d reason=%s',
                $subject,
                $outcome->get_status(),
                $entry ? $entry->get_points() : 0,
                $outcome->get_reason() ? $outcome->get_reason() : '-'
            )
        );
    }
}
