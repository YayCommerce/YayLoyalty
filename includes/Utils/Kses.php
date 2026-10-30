<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * wp_kses() allowlists for the small markup the plugin builds and prints, so those echoes escape at the point of
 * output. Each list holds exactly what its builders print: a tag or attribute missing here is silently stripped.
 * Not for the launcher or card templates: kses drops their color-mix() design variables, their <template> rows
 * and Tailwind classes such as `yl:[&>*]:size-4`, so those keep escaping each value inside the template.
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
}
