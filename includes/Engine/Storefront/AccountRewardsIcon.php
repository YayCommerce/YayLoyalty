<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Enums\CampaignTrigger;

defined( 'ABSPATH' ) || exit;

/**
 * The Rewards tab's and the launcher panel's inline SVG icons, from lucide or the design, in currentColor and decorative (aria-hidden).
 * No `yl:` class: the launcher's Tailwind scan would build it into the launcher's stylesheet.
 */
final class AccountRewardsIcon {

    private const PATHS = [
        'gift'     => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/>',
        'arrow'    => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'prev'     => '<path d="m15 18-6-6 6-6"/>',
        'next'     => '<path d="m9 18 6-6-6-6"/>',
    ];

    /** Not lucide: the design's own icons, drawn thinner than lucide, as [viewBox, paths]. */
    private const DESIGN = [
        'join'       => [ '0 0 18 18', '<path d="M13.5 15.75C13.5 14.1587 12.8679 12.6326 11.7426 11.5074C10.6174 10.3821 9.0913 9.75 7.5 9.75M7.5 9.75C5.9087 9.75 4.38258 10.3821 3.25736 11.5074C2.13214 12.6326 1.5 14.1587 1.5 15.75M7.5 9.75C9.57107 9.75 11.25 8.07107 11.25 6C11.25 3.92893 9.57107 2.25 7.5 2.25C5.42893 2.25 3.75 3.92893 3.75 6C3.75 8.07107 5.42893 9.75 7.5 9.75ZM16.5 15C16.5 12.4725 15 10.125 13.5 8.99996C13.9931 8.63004 14.3874 8.14428 14.648 7.58566C14.9086 7.02705 15.0274 6.41281 14.994 5.79731C14.9607 5.18181 14.7761 4.58403 14.4566 4.05686C14.1372 3.5297 13.6927 3.08941 13.1625 2.77496" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>' ],
        'earn'       => [ '0 0 18 18', '<path d="M12 6H7.5C7.10217 6 6.72064 6.15803 6.43934 6.43934C6.15803 6.72064 6 7.10217 6 7.5C6 7.89782 6.15803 8.27935 6.43934 8.56066C6.72064 8.84196 7.10217 9 7.5 9H10.5C10.8978 9 11.2794 9.15803 11.5607 9.43934C11.842 9.72064 12 10.1022 12 10.5C12 10.8978 11.842 11.2794 11.5607 11.5607C11.2794 11.842 10.8978 12 10.5 12H6M9 13.5V4.5M16.5 9C16.5 13.1421 13.1421 16.5 9 16.5C4.85786 16.5 1.5 13.1421 1.5 9C1.5 4.85786 4.85786 1.5 9 1.5C13.1421 1.5 16.5 4.85786 16.5 9Z" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>' ],
        'bag'        => [ '0 0 18 18', '<path d="M15.75 5.25V15.75H2.25V5.25L3.75 2.25H14.25L15.75 5.25ZM2.25 5.25H15.75M6 8.25C6 9.04565 6.31607 9.80871 6.87868 10.3713C7.44129 10.9339 8.20435 11.25 9 11.25C9.79565 11.25 10.5587 10.9339 11.1213 10.3713C11.6839 9.80871 12 9.04565 12 8.25" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>' ],
        'review'     => [ '0 0 18 18', '<path d="M15.9415 7.94995C15.9545 8.05889 15.9647 8.16876 15.9718 8.27946C16.0094 8.8606 16.0094 9.46243 15.9718 10.0436C15.7799 13.0132 13.4475 15.3787 10.5194 15.5733C9.52047 15.6397 8.4775 15.6396 7.48058 15.5733C7.13728 15.5505 6.76308 15.4686 6.4408 15.3358L6.44075 15.3358C6.08222 15.1882 5.90292 15.1143 5.81181 15.1255C5.72069 15.1367 5.58848 15.2342 5.32407 15.4292C4.85788 15.773 4.27057 16.0199 3.3996 15.9987H3.39959C2.95918 15.988 2.73897 15.9826 2.64039 15.8145C2.54181 15.6464 2.66459 15.4138 2.91015 14.9484C3.25072 14.303 3.46651 13.5642 3.13953 12.9722C2.5764 12.1266 2.09807 11.1251 2.02817 10.0436C1.99061 9.46243 1.99061 8.8606 2.02817 8.27946C2.22009 5.30981 4.55247 2.94434 7.48058 2.7497C8.32229 2.69375 9.19684 2.68494 10.05 2.72353" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M11.3438 7.499C10.8608 7.60227 10.6193 7.65391 10.4829 7.51748C10.3465 7.38104 10.3981 7.13956 10.5014 6.6566C10.6335 6.03896 10.6523 5.3408 11.1149 4.8782L13.4303 2.56271C13.6637 2.32937 13.7804 2.2127 13.9017 2.14262C14.2311 1.95246 14.6369 1.95246 14.9663 2.14262C15.0877 2.2127 15.2043 2.32937 15.4377 2.56271C15.671 2.79606 15.7877 2.91273 15.8578 3.03411C16.0479 3.36348 16.0479 3.76928 15.8578 4.09865C15.7877 4.22003 15.671 4.3367 15.4377 4.57004L13.1222 6.88553C12.6596 7.34812 11.9614 7.36694 11.3438 7.499Z" stroke-width="1.2"/><path d="M6.5498 11.1001H11.4498M6.5498 7.6001H8.2998" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>' ],
        'birthday'   => [ '0 0 18 20', '<path d="M10.3494 2.84997C10.3494 3.59556 9.745 4.19997 8.99941 4.19997C8.25383 4.19997 7.64941 3.59556 7.64941 2.84997C7.64941 2.10439 8.99941 0.599976 8.99941 0.599976C8.99941 0.599976 10.3494 2.10439 10.3494 2.84997Z" stroke-width="1.2" stroke-linejoin="round"/><path d="M9 4.19995V6.89995" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M16.6496 9.15002C16.6496 10.3927 15.5079 11.4 14.0996 11.4C12.8659 11.4 11.7859 10.627 11.5496 9.60002C11.3133 10.627 10.2333 11.4 8.9996 11.4C7.76594 11.4 6.68587 10.627 6.44961 9.60002C6.21334 10.627 5.13328 11.4 3.89961 11.4C2.49128 11.4 1.34961 10.3927 1.34961 9.15002C1.34961 7.90738 2.49128 6.90002 3.89961 6.90002H14.0996C15.5079 6.90002 16.6496 7.90738 16.6496 9.15002Z" stroke-width="1.2" stroke-linejoin="round"/><path d="M2.69922 11.4L3.16802 13.7217L3.16802 13.7217C3.64001 16.0592 3.876 17.2279 4.67569 17.914C5.47537 18.6 6.60168 18.6 8.85431 18.6H9.14412C11.3967 18.6 12.5231 18.6 13.3227 17.914C14.1224 17.2279 14.3584 16.0592 14.8304 13.7217L15.2992 11.4" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>' ],
        'redeem'     => [ '0 0 18 18', '<path d="M6.75 6.75H6.7575M11.25 6.75L6.75 11.25M11.25 11.25H11.2575M1.5 6.75C2.09674 6.75 2.66903 6.98705 3.09099 7.40901C3.51295 7.83097 3.75 8.40326 3.75 9C3.75 9.59674 3.51295 10.169 3.09099 10.591C2.66903 11.0129 2.09674 11.25 1.5 11.25V12.75C1.5 13.1478 1.65804 13.5294 1.93934 13.8107C2.22064 14.092 2.60218 14.25 3 14.25H15C15.3978 14.25 15.7794 14.092 16.0607 13.8107C16.342 13.5294 16.5 13.1478 16.5 12.75V11.25C15.9033 11.25 15.331 11.0129 14.909 10.591C14.4871 10.169 14.25 9.59674 14.25 9C14.25 8.40326 14.4871 7.83097 14.909 7.40901C15.331 6.98705 15.9033 6.75 16.5 6.75V5.25C16.5 4.85218 16.342 4.47064 16.0607 4.18934C15.7794 3.90804 15.3978 3.75 15 3.75H3C2.60218 3.75 2.22064 3.90804 1.93934 4.18934C1.65804 4.47064 1.5 4.85218 1.5 5.25V6.75Z" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>' ],
        'discount'   => [ '0 0 18 18', '<path d="M2.25 3.75H15.75V7.5C15.3522 7.5 14.9706 7.65804 14.6893 7.93934C14.408 8.22064 14.25 8.60218 14.25 9C14.25 9.39782 14.408 9.77936 14.6893 10.0607C14.9706 10.342 15.3522 10.5 15.75 10.5V14.25H2.25V10.5C2.64782 10.5 3.02936 10.342 3.31066 10.0607C3.59196 9.77936 3.75 9.39782 3.75 9C3.75 8.60218 3.59196 8.22064 3.31066 7.93934C3.02936 7.65804 2.64782 7.5 2.25 7.5V3.75Z" stroke-width="1.275" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.75 11.25L11.25 6.75" stroke-width="1.275" stroke-linecap="round" stroke-linejoin="round"/>' ],
        'offer'      => [ '0 0 18 18', '<path d="M11.625 11.375L6.375 6.14629M11.1875 6.53174V6.5625M6.8125 10.9375V10.9683M16 8.75C16 12.616 12.866 15.75 9 15.75C5.13401 15.75 2 12.616 2 8.75C2 4.88401 5.13401 1.75 9 1.75C12.866 1.75 16 4.88401 16 8.75Z" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>' ],
        'expiring'   => [ '0 0 18 18', '<path d="M15.75 9C15.75 12.7279 12.7279 15.75 9 15.75C5.27208 15.75 2.25 12.7279 2.25 9C2.25 5.27208 5.27208 2.25 9 2.25C12.7279 2.25 15.75 5.27208 15.75 9Z" stroke-width="1.275" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 5.25V9L11.25 10.5" stroke-width="1.275" stroke-linecap="round" stroke-linejoin="round"/>' ],
        'new_points' => [ '0 0 16 17', '<path d="M5.36924 10.3466L4.33396 6.48291M5.36924 10.3466L3.67887 10.7995M4.33396 6.48291L9.39009 1.95941C10.0652 1.35544 10.8772 0.925007 11.756 0.705328C11.8466 0.68269 11.9389 0.735426 11.9654 0.824933L14.8596 10.6023C14.8919 10.7114 14.83 10.8261 14.7211 10.859C13.8613 11.1186 12.9578 11.2005 12.0653 11.0997L6.38548 10.4579L5.36924 10.3466M4.33396 6.48291L1.91915 7.12996C1.38568 7.2729 1.0691 7.82124 1.21204 8.35471L1.72968 10.2866C1.87262 10.82 2.42096 11.1366 2.95443 10.9937L3.67887 10.7995M3.67887 10.7995L5.35359 14.6455C5.55121 15.0993 6.05112 15.3403 6.52925 15.2122L7.0254 15.0792C7.60381 14.9242 7.91726 14.2991 7.69545 13.7429L6.38548 10.4579" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.3223 4.53015C13.3223 4.53015 14.8301 4.21917 15.202 5.60715C15.5739 6.99513 14.1126 7.47972 14.1126 7.47972" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>' ],
    ];

    private const EMPTY_ACTIVITY = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 35" fill="none" aria-hidden="true" focusable="false">'
        . '<path d="M18 34.9999C27.9411 34.9999 36 34.0113 36 32.7917C36 31.5721 27.9411 30.5835 18 30.5835C8.05887 30.5835 0 31.5721 0 32.7917C0 34.0113 8.05887 34.9999 18 34.9999Z" fill="#F5F5F7"/>'
        . '<path d="M7.49077 2.09762C7.71438 0.919909 8.34792 0.331055 9.3914 0.331055H20.7951C21.9877 0.331055 22.6957 0.956712 22.9193 2.20803L26.3852 30.0314C26.5343 31.2091 26.0125 31.7979 24.82 31.7979H6.26096C5.06841 31.7979 4.54667 31.1723 4.69574 29.921L7.49077 2.09762Z" fill="url(#{id}-0)" stroke-width="1.2" stroke-linejoin="round" stroke="#D6D6D9"/>'
        . '<path d="M9.95009 0.220703H23.5898C25.006 0.220703 25.7513 0.919967 25.8259 2.31849L27.2793 30.3627C27.3538 31.614 26.7575 32.2764 25.4905 32.35L11.5153 33.1229C10.0992 33.1965 9.35382 32.5341 9.27928 31.1355L7.71407 2.53932C7.63953 0.993574 8.38487 0.220703 9.95009 0.220703Z" fill="url(#{id}-1)" stroke-width="1.2" stroke-linejoin="round" stroke="#D6D6D9"/>'
        . '<path d="M17.5527 8.28076C16.77 8.28076 16.211 8.83281 16.3228 9.71609L16.6582 15.7886C16.6582 16.7823 18.3353 16.7823 18.4471 15.7886L18.8943 9.71609C19.0061 8.83281 18.3353 8.28076 17.5527 8.28076Z" fill="url(#{id}-2)"/>'
        . '<path d="M17.5526 19.9843C18.2935 19.9843 18.8942 19.3911 18.8942 18.6594C18.8942 17.9277 18.2935 17.3345 17.5526 17.3345C16.8116 17.3345 16.2109 17.9277 16.2109 18.6594C16.2109 19.3911 16.8116 19.9843 17.5526 19.9843Z" fill="url(#{id}-3)"/>'
        . '<path d="M26.8319 34.2271C31.1542 34.2271 34.658 30.8162 34.658 26.6088C34.658 22.4013 31.1542 18.9905 26.8319 18.9905C22.5097 18.9905 19.0059 22.4013 19.0059 26.6088C19.0059 30.8162 22.5097 34.2271 26.8319 34.2271Z" fill="#EEEEF0" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" stroke="#D2D2D5"/>'
        . '<path d="M26.0492 34.2271C30.3097 34.2271 33.7635 30.8162 33.7635 26.6088C33.7635 22.4013 30.3097 18.9905 26.0492 18.9905C21.7888 18.9905 18.335 22.4013 18.335 26.6088C18.335 30.8162 21.7888 34.2271 26.0492 34.2271Z" fill="url(#{id}-4)" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" stroke="#D2D2D5"/>'
        . '<path d="M26.049 22.6339C26.1235 22.4867 26.2353 22.5603 26.3844 22.8547L27.3906 25.0629L29.8502 25.3942C30.1484 25.3942 30.1856 25.5414 29.962 25.8358L28.0614 27.6024L28.5086 30.0314C28.5831 30.3258 28.4713 30.3994 28.1732 30.2522L26.049 29.0377L23.9248 30.2522C23.6266 30.3994 23.5148 30.3258 23.5893 30.0314L24.0366 27.6024L22.1359 25.8358C21.9123 25.5414 21.9496 25.3942 22.2477 25.3942L24.7074 25.0629L25.7136 22.8547C25.7881 22.7075 25.8999 22.6339 26.049 22.6339Z" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" stroke="#D2D2D5"/>'
        . '<defs>'
        . '<linearGradient id="{id}-0" x1="3.24233" y1="6.2932" x2="10.6722" y2="31.187" gradientUnits="userSpaceOnUse"><stop stop-color="#FAFAFA"/><stop offset="1" stop-color="#EEEEEF"/></linearGradient>'
        . '<linearGradient id="{id}-1" x1="6.70786" y1="-0.110527" x2="26.0295" y2="31.6827" gradientUnits="userSpaceOnUse"><stop stop-color="white"/><stop offset="1" stop-color="#F7F7F8"/></linearGradient>'
        . '<linearGradient id="{id}-2" x1="17.4409" y1="8.61199" x2="18.8589" y2="19.3264" gradientUnits="userSpaceOnUse"><stop stop-color="#D5D5D8"/><stop offset="1" stop-color="#DCDCDF"/></linearGradient>'
        . '<linearGradient id="{id}-3" x1="17.4408" y1="8.61208" x2="18.8588" y2="19.3265" gradientUnits="userSpaceOnUse"><stop stop-color="#D5D5D8"/><stop offset="1" stop-color="#DCDCDF"/></linearGradient>'
        . '<linearGradient id="{id}-4" x1="22.4716" y1="18.2176" x2="31.6943" y2="33.2266" gradientUnits="userSpaceOnUse"><stop stop-color="white"/><stop offset="1" stop-color="#F4F4F5"/></linearGradient>'
        . '</defs></svg>';

    private const EMPTY_HISTORY = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 54 53" fill="none" aria-hidden="true" focusable="false">'
        . '<path d="M27 53.0002C41.9117 53.0002 54 51.5031 54 49.6563C54 47.8096 41.9117 46.3125 27 46.3125C12.0883 46.3125 0 47.8096 0 49.6563C0 51.5031 12.0883 53.0002 27 53.0002Z" fill="#F5F5F7"/>'
        . '<path d="M11.2357 3.17654C11.5711 1.39316 12.5214 0.501465 14.0866 0.501465H31.1922C32.981 0.501465 34.0431 1.44889 34.3785 3.34374L39.5773 45.4762C39.8009 47.2596 39.0183 48.1513 37.2295 48.1513H9.39095C7.60213 48.1513 6.81952 47.2039 7.04313 45.309L11.2357 3.17654Z" fill="url(#{id}-0)" stroke-width="1.2" stroke-linejoin="round" stroke="#D6D6D9"/>'
        . '<path d="M14.9251 0.334473H35.3848C37.509 0.334473 38.627 1.39336 38.7388 3.51113L40.9189 45.978C41.0307 47.8729 40.1363 48.876 38.2357 48.9875L17.273 50.1578C15.1487 50.2693 14.0307 49.2661 13.9189 47.1484L11.5711 3.84551C11.4593 1.50482 12.5773 0.334473 14.9251 0.334473Z" fill="url(#{id}-1)" stroke-width="1.2" stroke-linejoin="round" stroke="#D6D6D9"/>'
        . '<path d="M26.329 12.5396C25.1551 12.5396 24.3166 13.3755 24.4843 14.7131L24.9874 23.9086C24.9874 25.4134 27.5029 25.4134 27.6706 23.9086L28.3414 14.7131C28.5091 13.3755 27.5029 12.5396 26.329 12.5396Z" fill="url(#{id}-2)"/>'
        . '<path d="M26.3288 30.2621C27.4403 30.2621 28.3413 29.3639 28.3413 28.2558C28.3413 27.1478 27.4403 26.2495 26.3288 26.2495C25.2174 26.2495 24.3164 27.1478 24.3164 28.2558C24.3164 29.3639 25.2174 30.2621 26.3288 30.2621Z" fill="url(#{id}-3)"/>'
        . '<path d="M40.2479 51.8299C46.7313 51.8299 51.9871 46.6649 51.9871 40.2936C51.9871 33.9223 46.7313 28.7573 40.2479 28.7573C33.7646 28.7573 28.5088 33.9223 28.5088 40.2936C28.5088 46.6649 33.7646 51.8299 40.2479 51.8299Z" fill="#EEEEF0" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" stroke="#D2D2D5"/>'
        . '<path d="M39.0744 51.8299C45.4651 51.8299 50.6458 46.6649 50.6458 40.2936C50.6458 33.9223 45.4651 28.7573 39.0744 28.7573C32.6836 28.7573 27.5029 33.9223 27.5029 40.2936C27.5029 46.6649 32.6836 51.8299 39.0744 51.8299Z" fill="url(#{id}-4)" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" stroke="#D2D2D5"/>'
        . '<path d="M39.0744 34.2745C39.1862 34.0516 39.3539 34.1631 39.5775 34.6089L41.0869 37.9528L44.7763 38.4544C45.2235 38.4544 45.2794 38.6773 44.944 39.1231L42.0931 41.7982L42.7639 45.4764C42.8757 45.9223 42.708 46.0337 42.2608 45.8108L39.0744 43.9717L35.8881 45.8108C35.4409 46.0337 35.2732 45.9223 35.385 45.4764L36.0558 41.7982L33.2049 39.1231C32.8695 38.6773 32.9254 38.4544 33.3726 38.4544L37.062 37.9528L38.5713 34.6089C38.6831 34.386 38.8508 34.2745 39.0744 34.2745Z" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" stroke="#D2D2D5"/>'
        . '<defs>'
        . '<linearGradient id="{id}-0" x1="4.863" y1="9.52986" x2="16.2033" y2="47.1673" gradientUnits="userSpaceOnUse"><stop stop-color="#FAFAFA"/><stop offset="1" stop-color="#EEEEEF"/></linearGradient>'
        . '<linearGradient id="{id}-1" x1="10.0618" y1="-0.167105" x2="39.4472" y2="47.7297" gradientUnits="userSpaceOnUse"><stop stop-color="white"/><stop offset="1" stop-color="#F7F7F8"/></linearGradient>'
        . '<linearGradient id="{id}-2" x1="26.1613" y1="13.0411" x2="28.3284" y2="29.2605" gradientUnits="userSpaceOnUse"><stop stop-color="#D5D5D8"/><stop offset="1" stop-color="#DCDCDF"/></linearGradient>'
        . '<linearGradient id="{id}-3" x1="26.1611" y1="13.0413" x2="28.3283" y2="29.2607" gradientUnits="userSpaceOnUse"><stop stop-color="#D5D5D8"/><stop offset="1" stop-color="#DCDCDF"/></linearGradient>'
        . '<linearGradient id="{id}-4" x1="33.7079" y1="27.587" x2="47.7331" y2="50.1962" gradientUnits="userSpaceOnUse"><stop stop-color="white"/><stop offset="1" stop-color="#F4F4F5"/></linearGradient>'
        . '</defs></svg>';

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

    /** One of the design's icons by name (DESIGN); '' for an unknown name. */
    public static function design( string $name ): string {
        if ( ! isset( self::DESIGN[ $name ] ) ) {
            return '';
        }

        [ $view_box, $paths ] = self::DESIGN[ $name ];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $view_box . '" fill="none" stroke="currentColor" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }

    /** A "Ways to earn" row's icon by its trigger; a gift for one Lite doesn't know. */
    public static function for_trigger( string $trigger ): string {
        $names = [
            CampaignTrigger::PURCHASE => 'bag',
            CampaignTrigger::REVIEW   => 'review',
            CampaignTrigger::SIGNUP   => 'join',
            CampaignTrigger::BIRTHDAY => 'birthday',
        ];

        return isset( $names[ $trigger ] ) ? self::design( $names[ $trigger ] ) : self::get( 'gift' );
    }

    /** A "How it works" step's icon by its trigger: as in "Ways to earn", but a coin for the purchase step. */
    public static function for_step( string $trigger ): string {
        return CampaignTrigger::PURCHASE === $trigger ? self::design( 'earn' ) : self::for_trigger( $trigger );
    }

    /**
     * The empty-activity illustration from the design: a page with "!" and a star badge, in its own greys.
     */
    public static function empty_activity(): string {
        return self::with_ids( self::EMPTY_ACTIVITY );
    }

    /** The points history's empty state: empty_activity()'s drawing at 54x53, with lines kept at 1.2px. */
    public static function empty_history(): string {
        return self::with_ids( self::EMPTY_HISTORY );
    }

    /** Gradient ids take a per-call suffix, since the launcher and the Rewards tab can both draw one on a page. */
    private static function with_ids( string $svg ): string {
        static $count = 0;
        ++$count;

        return str_replace( '{id}', 'yl-empty-' . $count, $svg );
    }
}
