<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Points\Result;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The one debug line every listener writes for a preview, so the three event
 * families read the same in the log. Goes away with the preview calls when
 * the listeners switch to award() (step 3).
 */
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
}
