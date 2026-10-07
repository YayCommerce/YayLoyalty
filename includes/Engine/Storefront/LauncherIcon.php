<?php
namespace YayLoyalty\Engine\Storefront;

defined( 'ABSPATH' ) || exit;

/** The launcher's inline SVG icons, in currentColor so they follow the button's foreground. */
final class LauncherIcon {

    /** lucide's Gift, on the launcher button and the panel header. */
    public static function gift(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="yl:size-5 yl:shrink-0"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/></svg>';
    }

    /** lucide's X, for the panel's close button. */
    public static function close(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="yl:size-5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
    }
}
