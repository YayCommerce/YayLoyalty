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

    /** Not lucide: the guest panel's "How it works" icons from the design, as [viewBox, paths], by trigger plus 'redeem'. */
    private const STEPS = [
        CampaignTrigger::SIGNUP   => [ '0 0 18 18', '<path d="M13.5 15.75C13.5 14.1587 12.8679 12.6326 11.7426 11.5074C10.6174 10.3821 9.0913 9.75 7.5 9.75M7.5 9.75C5.9087 9.75 4.38258 10.3821 3.25736 11.5074C2.13214 12.6326 1.5 14.1587 1.5 15.75M7.5 9.75C9.57107 9.75 11.25 8.07107 11.25 6C11.25 3.92893 9.57107 2.25 7.5 2.25C5.42893 2.25 3.75 3.92893 3.75 6C3.75 8.07107 5.42893 9.75 7.5 9.75ZM16.5 15C16.5 12.4725 15 10.125 13.5 8.99996C13.9931 8.63004 14.3874 8.14428 14.648 7.58566C14.9086 7.02705 15.0274 6.41281 14.994 5.79731C14.9607 5.18181 14.7761 4.58403 14.4566 4.05686C14.1372 3.5297 13.6927 3.08941 13.1625 2.77496" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>' ],
        CampaignTrigger::PURCHASE => [ '0 0 18 18', '<path d="M12 6H7.5C7.10217 6 6.72064 6.15803 6.43934 6.43934C6.15803 6.72064 6 7.10217 6 7.5C6 7.89782 6.15803 8.27935 6.43934 8.56066C6.72064 8.84196 7.10217 9 7.5 9H10.5C10.8978 9 11.2794 9.15803 11.5607 9.43934C11.842 9.72064 12 10.1022 12 10.5C12 10.8978 11.842 11.2794 11.5607 11.5607C11.2794 11.842 10.8978 12 10.5 12H6M9 13.5V4.5M16.5 9C16.5 13.1421 13.1421 16.5 9 16.5C4.85786 16.5 1.5 13.1421 1.5 9C1.5 4.85786 4.85786 1.5 9 1.5C13.1421 1.5 16.5 4.85786 16.5 9Z" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>' ],
        CampaignTrigger::REVIEW   => [ '0 0 18 18', '<path d="M15.9415 7.94995C15.9545 8.05889 15.9647 8.16876 15.9718 8.27946C16.0094 8.8606 16.0094 9.46243 15.9718 10.0436C15.7799 13.0132 13.4475 15.3787 10.5194 15.5733C9.52047 15.6397 8.4775 15.6396 7.48058 15.5733C7.13728 15.5505 6.76308 15.4686 6.4408 15.3358L6.44075 15.3358C6.08222 15.1882 5.90292 15.1143 5.81181 15.1255C5.72069 15.1367 5.58848 15.2342 5.32407 15.4292C4.85788 15.773 4.27057 16.0199 3.3996 15.9987H3.39959C2.95918 15.988 2.73897 15.9826 2.64039 15.8145C2.54181 15.6464 2.66459 15.4138 2.91015 14.9484C3.25072 14.303 3.46651 13.5642 3.13953 12.9722C2.5764 12.1266 2.09807 11.1251 2.02817 10.0436C1.99061 9.46243 1.99061 8.8606 2.02817 8.27946C2.22009 5.30981 4.55247 2.94434 7.48058 2.7497C8.32229 2.69375 9.19684 2.68494 10.05 2.72353" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M11.3438 7.499C10.8608 7.60227 10.6193 7.65391 10.4829 7.51748C10.3465 7.38104 10.3981 7.13956 10.5014 6.6566C10.6335 6.03896 10.6523 5.3408 11.1149 4.8782L13.4303 2.56271C13.6637 2.32937 13.7804 2.2127 13.9017 2.14262C14.2311 1.95246 14.6369 1.95246 14.9663 2.14262C15.0877 2.2127 15.2043 2.32937 15.4377 2.56271C15.671 2.79606 15.7877 2.91273 15.8578 3.03411C16.0479 3.36348 16.0479 3.76928 15.8578 4.09865C15.7877 4.22003 15.671 4.3367 15.4377 4.57004L13.1222 6.88553C12.6596 7.34812 11.9614 7.36694 11.3438 7.499Z" stroke-width="1.2"/><path d="M6.5498 11.1001H11.4498M6.5498 7.6001H8.2998" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>' ],
        CampaignTrigger::BIRTHDAY => [ '0 0 18 20', '<path d="M10.3494 2.84997C10.3494 3.59556 9.745 4.19997 8.99941 4.19997C8.25383 4.19997 7.64941 3.59556 7.64941 2.84997C7.64941 2.10439 8.99941 0.599976 8.99941 0.599976C8.99941 0.599976 10.3494 2.10439 10.3494 2.84997Z" stroke-width="1.2" stroke-linejoin="round"/><path d="M9 4.19995V6.89995" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M16.6496 9.15002C16.6496 10.3927 15.5079 11.4 14.0996 11.4C12.8659 11.4 11.7859 10.627 11.5496 9.60002C11.3133 10.627 10.2333 11.4 8.9996 11.4C7.76594 11.4 6.68587 10.627 6.44961 9.60002C6.21334 10.627 5.13328 11.4 3.89961 11.4C2.49128 11.4 1.34961 10.3927 1.34961 9.15002C1.34961 7.90738 2.49128 6.90002 3.89961 6.90002H14.0996C15.5079 6.90002 16.6496 7.90738 16.6496 9.15002Z" stroke-width="1.2" stroke-linejoin="round"/><path d="M2.69922 11.4L3.16802 13.7217L3.16802 13.7217C3.64001 16.0592 3.876 17.2279 4.67569 17.914C5.47537 18.6 6.60168 18.6 8.85431 18.6H9.14412C11.3967 18.6 12.5231 18.6 13.3227 17.914C14.1224 17.2279 14.3584 16.0592 14.8304 13.7217L15.2992 11.4" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>' ],
        'redeem'                  => [ '0 0 18 18', '<path d="M6.75 6.75H6.7575M11.25 6.75L6.75 11.25M11.25 11.25H11.2575M1.5 6.75C2.09674 6.75 2.66903 6.98705 3.09099 7.40901C3.51295 7.83097 3.75 8.40326 3.75 9C3.75 9.59674 3.51295 10.169 3.09099 10.591C2.66903 11.0129 2.09674 11.25 1.5 11.25V12.75C1.5 13.1478 1.65804 13.5294 1.93934 13.8107C2.22064 14.092 2.60218 14.25 3 14.25H15C15.3978 14.25 15.7794 14.092 16.0607 13.8107C16.342 13.5294 16.5 13.1478 16.5 12.75V11.25C15.9033 11.25 15.331 11.0129 14.909 10.591C14.4871 10.169 14.25 9.59674 14.25 9C14.25 8.40326 14.4871 7.83097 14.909 7.40901C15.331 6.98705 15.9033 6.75 16.5 6.75V5.25C16.5 4.85218 16.342 4.47064 16.0607 4.18934C15.7794 3.90804 15.3978 3.75 15 3.75H3C2.60218 3.75 2.22064 3.90804 1.93934 4.18934C1.65804 4.47064 1.5 4.85218 1.5 5.25V6.75Z" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>' ],
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

    /** A "How it works" step's icon by its trigger, or 'redeem'; the "Ways to earn" icon for a step without its own. */
    public static function for_step( string $step ): string {
        if ( ! isset( self::STEPS[ $step ] ) ) {
            return self::for_trigger( $step );
        }

        [ $view_box, $paths ] = self::STEPS[ $step ];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $view_box . '" fill="none" stroke="currentColor" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }
}
