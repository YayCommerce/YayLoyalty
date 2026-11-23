<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * wp_kses() allowlists for the markup the plugin builds and prints, so those echoes escape at the point of
 * output. Each list holds exactly what its builders print: a tag or attribute missing here is silently stripped.
 */
final class Kses {

    /** The inline icons (LauncherIcon, AccountMyPointsIcon), gradients included. */
    public static function svg(): array {
        $paint = [
            'fill'            => true,
            'stroke'          => true,
            'stroke-width'    => true,
            'stroke-linecap'  => true,
            'stroke-linejoin' => true,
            'opacity'         => true,
        ];

        return [
            'svg'            => $paint + [
                'xmlns'       => true,
                'viewbox'     => true,
                'width'       => true,
                'height'      => true,
                'class'       => true,
                'aria-hidden' => true,
                'focusable'   => true,
                'role'        => true,
            ],
            'g'              => $paint,
            'path'           => $paint + [ 'd' => true ],
            'rect'           => $paint + [
                'x'      => true,
                'y'      => true,
                'width'  => true,
                'height' => true,
                'rx'     => true,
                'ry'     => true,
            ],
            'circle'         => $paint + [
                'cx' => true,
                'cy' => true,
                'r'  => true,
            ],
            'line'           => $paint + [
                'x1' => true,
                'y1' => true,
                'x2' => true,
                'y2' => true,
            ],
            'defs'           => [],
            'lineargradient' => [
                'id'            => true,
                'x1'            => true,
                'y1'            => true,
                'x2'            => true,
                'y2'            => true,
                'gradientunits' => true,
            ],
            'stop'           => [
                'offset'       => true,
                'stop-color'   => true,
                'stop-opacity' => true,
            ],
        ];
    }

    /** A sentence with its number or price wrapped apart, as LauncherCopy and wc_price() build them. */
    public static function inline(): array {
        // translate and dir are on wc_price()'s currency symbol.
        $attrs = [
            'class'       => true,
            'aria-hidden' => true,
            'translate'   => true,
            'dir'         => true,
        ];

        return [
            'span'   => $attrs,
            'strong' => $attrs,
            'em'     => $attrs,
            'b'      => $attrs,
            'bdi'    => [],
        ];
    }

    /**
     * A `safecss_filter_attr_allow_css` callback: safecss_filter_attr() refuses any function it doesn't know,
     * color-mix() included. Only a custom property mixing two hex colors, as the launcher's style builds it, gets through.
     */
    public static function allow_color_mix( bool $allow_css, string $css_test_string ): bool {
        return $allow_css || 1 === preg_match( '/^--[a-z0-9-]+:\s*color-mix\(in srgb,\s*#[0-9a-f]{3,8}(\s+\d{1,3}%)?,\s*#[0-9a-f]{3,8}(\s+\d{1,3}%)?\)$/i', $css_test_string );
    }

    /**
     * Every tag and attribute the storefront templates print (launcher, redeem card, My Account tabs, placements,
     * product and order-received messages), icons and prices included.
     */
    public static function storefront(): array {
        $global = [
            'class'            => true,
            'id'               => true,
            'role'             => true,
            'hidden'           => true,
            'style'            => true,
            'tabindex'         => true,
            'title'            => true,
            'dir'              => true,
            'translate'        => true,
            'data-*'           => true,
            'aria-controls'    => true,
            'aria-current'     => true,
            'aria-describedby' => true,
            'aria-disabled'    => true,
            'aria-expanded'    => true,
            'aria-hidden'      => true,
            'aria-label'       => true,
            'aria-labelledby'  => true,
            'aria-live'        => true,
            'aria-modal'       => true,
            'aria-pressed'     => true,
        ];

        $tags = [];
        foreach ( [ 'div', 'span', 'p', 'strong', 'em', 'b', 'i', 'bdi', 'br', 'h2', 'h3', 'h4', 'section', 'nav', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'template' ] as $tag ) {
            $tags[ $tag ] = $global;
        }

        $tags['th']     = $global + [ 'scope' => true ];
        $tags['td']     = $global + [ 'colspan' => true ];
        $tags['a']      = $global + [
            'href'   => true,
            'target' => true,
            'rel'    => true,
        ];
        $tags['img']    = $global + [
            'src'      => true,
            'alt'      => true,
            'width'    => true,
            'height'   => true,
            'loading'  => true,
            'decoding' => true,
        ];
        $tags['form']   = $global + [
            'action' => true,
            'method' => true,
        ];
        $tags['label']  = $global + [ 'for' => true ];
        $tags['button'] = $global + [
            'type'     => true,
            'name'     => true,
            'value'    => true,
            'disabled' => true,
        ];
        $tags['input']  = $global + [
            'type'         => true,
            'name'         => true,
            'value'        => true,
            'placeholder'  => true,
            'autocomplete' => true,
            'inputmode'    => true,
            'pattern'      => true,
            'min'          => true,
            'max'          => true,
            'step'         => true,
            'checked'      => true,
            'disabled'     => true,
            'readonly'     => true,
            'required'     => true,
        ];

        return array_merge( $tags, self::svg() );
    }
}
