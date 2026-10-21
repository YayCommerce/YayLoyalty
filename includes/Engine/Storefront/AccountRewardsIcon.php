<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Enums\CampaignTrigger;

defined( 'ABSPATH' ) || exit;

/**
 * The Rewards tab's and the launcher panel's inline SVG icons, from lucide, in currentColor and decorative (aria-hidden).
 * No `yl:` class: the launcher's Tailwind scan would build it into the launcher's stylesheet.
 */
final class AccountRewardsIcon {

    private const PATHS = [
        'bag'       => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'message'   => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'cake'      => '<path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20"/><path d="M7 8v3"/><path d="M12 8v3"/><path d="M17 8v3"/><path d="M7 4h.01"/><path d="M12 4h.01"/><path d="M17 4h.01"/>',
        'user'      => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
        'gift'      => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/>',
        'coins'     => '<circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/>',
        'percent'   => '<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m15 9-6 6"/><path d="M9 9h.01"/><path d="M15 15h.01"/>',
        'arrow'     => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'megaphone' => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'external'  => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'prev'      => '<path d="m15 18-6-6 6-6"/>',
        'next'      => '<path d="m9 18 6-6-6-6"/>',
        'ticket'    => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>',
        // Not lucide: the empty-history mark from the design, a page with "!" and a star badge on its corner.
        'empty'     => '<path d="M12 18H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v7"/><path d="M10.5 6v4.5"/><path d="M10.5 13.5h.01"/><circle cx="17" cy="17" r="4.5"/><path d="M17 14.5l.62 1.65 1.76.08-1.38 1.09.47 1.7L17 18.05l-1.47.97.47-1.7-1.38-1.09 1.76-.08Z"/>',
    ];

    /**
     * One icon by name; '' for an unknown name.
     *
     * @param string $css_class Classes for the <svg>, as the launcher's literal `yl:` list (LauncherIcon); '' for none.
     */
    public static function get( string $name, string $css_class = '' ): string {
        if ( ! isset( self::PATHS[ $name ] ) ) {
            return '';
        }

        $class_attr = '' === $css_class ? '' : ' class="' . esc_attr( $css_class ) . '"';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"' . $class_attr . '>' . self::PATHS[ $name ] . '</svg>';
    }

    /** A "Ways to earn" row's icon by its trigger; a gift for one Lite doesn't know. */
    public static function for_trigger( string $trigger ): string {
        $names = [
            CampaignTrigger::PURCHASE => 'bag',
            CampaignTrigger::REVIEW   => 'message',
            CampaignTrigger::SIGNUP   => 'user',
            CampaignTrigger::BIRTHDAY => 'cake',
        ];

        return self::get( $names[ $trigger ] ?? 'gift' );
    }
}
