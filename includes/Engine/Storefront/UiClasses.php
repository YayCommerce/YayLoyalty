<?php
namespace YayLoyalty\Engine\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * The admin kit's class lists for the storefront templates, so the launcher looks like the admin UI.
 *
 * Mirrors the `cva` definitions in apps/admin/src/components/ui/{button,badge}.tsx, trimmed to the
 * variants the launcher uses and written with the storefront build's `yl:` prefix. The strings stay
 * literal because Tailwind finds classes by scanning this file (apps/admin/src/storefront.css @source);
 * a class built at runtime would never reach the stylesheet. Allowed mirror: code-standards.md § 16.
 */
final class UiClasses {

    private const BUTTON_BASE = 'yl:inline-flex yl:items-center yl:justify-center yl:gap-1 yl:whitespace-nowrap yl:rounded-md yl:text-sm yl:font-medium yl:cursor-pointer yl:transition-colors yl:shrink-0 yl:no-underline yl:outline-none yl:focus-visible:ring-[1.5px] yl:focus-visible:ring-ring yl:focus-visible:ring-offset-2 yl:disabled:pointer-events-none yl:disabled:opacity-50';

    private const BUTTON_VARIANTS = [
        'primary' => 'yl:bg-primary yl:text-primary-foreground yl:shadow-xs yl:hover:bg-primary-accent yl:border yl:border-transparent',
        'outline' => 'yl:border yl:border-border yl:bg-background yl:text-foreground yl:shadow-xs yl:hover:bg-accent yl:hover:text-accent-foreground',
        'ghost'   => 'yl:bg-transparent yl:text-foreground yl:hover:bg-accent yl:border-0',
        'link'    => 'yl:bg-transparent yl:text-foreground yl:hover:text-primary yl:hover:underline yl:underline-offset-4 yl:border-0',
    ];

    private const BUTTON_SIZES = [
        'default' => 'yl:h-9 yl:px-4 yl:py-2',
        'sm'      => 'yl:h-8 yl:rounded-sm yl:px-3',
        'icon'    => 'yl:size-9',
        'icon-sm' => 'yl:size-8',
        // The caller sizes it: two height or padding utilities on one element would fight over source order.
        'none'    => '',
    ];

    private const BADGE_BASE = 'yl:inline-flex yl:items-center yl:justify-center yl:rounded-full yl:border yl:h-5 yl:px-2 yl:text-xs yl:font-medium yl:w-fit yl:whitespace-nowrap yl:shrink-0 yl:gap-1';

    private const BADGE_VARIANTS = [
        'primary'      => 'yl:border-transparent yl:bg-primary yl:text-primary-foreground',
        'primary-soft' => 'yl:border-transparent yl:bg-primary/6 yl:text-primary',
        'success-soft' => 'yl:border-transparent yl:bg-success/6 yl:text-success',
        'dimmed'       => 'yl:border-transparent yl:bg-muted yl:text-foreground',
    ];

    /** `<Card>`: the bordered block every panel section sits in. */
    public const CARD = 'yl:bg-card yl:text-card-foreground yl:flex yl:flex-col yl:gap-3 yl:rounded-lg yl:border yl:border-border yl:p-4';

    /** `<Separator>` horizontal. */
    public const SEPARATOR = 'yl:bg-border yl:h-px yl:w-full yl:shrink-0 yl:border-0 yl:m-0';

    /** `<Skeleton>` card tone. */
    public const SKELETON = 'yl:animate-pulse yl:rounded-md yl:bg-muted';

    /** `<SheetTitle>` / `<CardDescription>`. */
    public const TITLE       = 'yl:text-foreground yl:m-0 yl:text-base yl:font-semibold yl:leading-tight';
    public const DESCRIPTION = 'yl:text-muted-foreground yl:m-0 yl:text-sm yl:font-normal';

    /** Section heading inside the panel ("Ways to earn"). */
    public const SECTION_HEADING = 'yl:text-muted-foreground yl:m-0 yl:text-xs yl:font-semibold yl:uppercase yl:tracking-wide';

    public static function button( string $variant = 'primary', string $size = 'default' ): string {
        return self::BUTTON_BASE . ' '
            . ( self::BUTTON_VARIANTS[ $variant ] ?? self::BUTTON_VARIANTS['primary'] ) . ' '
            . ( self::BUTTON_SIZES[ $size ] ?? self::BUTTON_SIZES['default'] );
    }

    public static function badge( string $variant = 'primary' ): string {
        return self::BADGE_BASE . ' ' . ( self::BADGE_VARIANTS[ $variant ] ?? self::BADGE_VARIANTS['primary'] );
    }
}
