<?php
namespace YayLoyalty\Engine\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * The admin kit's class lists the storefront templates still borrow, with the storefront build's `yl:` prefix.
 * The strings stay literal: Tailwind finds classes by scanning this file (the @source in
 * apps/admin/src/storefront.css), so a class built at runtime never reaches the stylesheet.
 */
final class UiClasses {

    /** `<Skeleton>` card tone. */
    public const SKELETON = 'yl:animate-pulse yl:rounded-md yl:bg-muted';
}
