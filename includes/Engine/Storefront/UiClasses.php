<?php
namespace YayLoyalty\Engine\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * The admin kit's class lists for the storefront templates, so the launcher looks like the admin UI.
 *
 * Mirrors the `cva` definitions in apps/admin/src/components/ui/button.tsx, trimmed to the
 * variants the launcher uses (add one back when a template first needs it: every listed class ships)
 * and written with the storefront build's `yl:` prefix. The strings stay literal because Tailwind
 * finds classes by scanning this file (apps/admin/src/storefront.css @source); a class built at
 * runtime would never reach the stylesheet. button.tsx owns these lists; change both together.
 */
final class UiClasses {

    private const BUTTON_BASE = 'yl:inline-flex yl:items-center yl:justify-center yl:gap-1 yl:whitespace-nowrap yl:rounded-md yl:text-sm yl:font-medium yl:cursor-pointer yl:transition-colors yl:shrink-0 yl:no-underline yl:outline-none yl:focus-visible:ring-[1.5px] yl:focus-visible:ring-ring yl:focus-visible:ring-offset-2 yl:disabled:pointer-events-none yl:disabled:opacity-50';

    private const BUTTON_VARIANTS = [
        'primary'         => 'yl:bg-primary yl:text-primary-foreground yl:shadow-xs yl:hover:bg-primary-accent yl:border yl:border-transparent',
        'primary-outline' => 'yl:border yl:border-primary yl:bg-transparent yl:text-primary yl:hover:border-primary-accent yl:hover:text-primary-accent',
    ];

    private const BUTTON_SIZES = [
        'default' => 'yl:h-9 yl:px-4 yl:py-2',
    ];

    /** `<Skeleton>` card tone. */
    public const SKELETON = 'yl:animate-pulse yl:rounded-md yl:bg-muted';

    /** One panel block (balance, ways to earn, …): full-bleed, ruled off from the next. */
    public const PANEL_SECTION = 'yl:flex yl:flex-col yl:gap-1 yl:border-b yl:border-border yl:px-4 yl:py-3.5 yl:last:border-b-0';

    /** Section heading inside the panel ("Ways to earn"). */
    public const SECTION_HEADING = 'yl:text-muted-foreground yl:m-0 yl:pb-1 yl:text-[11px] yl:font-semibold yl:uppercase yl:tracking-[0.06em]';

    /** A label-left, value-right line in a panel list. */
    public const PANEL_ROW = 'yl:flex yl:items-baseline yl:justify-between yl:gap-2.5 yl:border-b yl:border-divider yl:py-2.5 yl:text-xs yl:last:border-b-0';

    public static function button( string $variant = 'primary', string $size = 'default' ): string {
        return self::BUTTON_BASE . ' '
            . ( self::BUTTON_VARIANTS[ $variant ] ?? self::BUTTON_VARIANTS['primary'] ) . ' '
            . ( self::BUTTON_SIZES[ $size ] ?? self::BUTTON_SIZES['default'] );
    }
}
