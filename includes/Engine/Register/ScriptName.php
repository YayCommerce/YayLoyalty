<?php
namespace YayLoyalty\Engine\Register;

/** Handles of the plugin's registered scripts and styles. */
class ScriptName {

    public const STYLE_SETTINGS = 'yayloyalty/style/settings';

    public const MODULE_PREFIX = 'yayloyalty/module/';

    public const PAGE_SETTINGS = 'yayloyalty/module/page-settings';

    /** Storefront launcher: plain (non-module) script, deferred, and its stylesheet. */
    public const STOREFRONT_SCRIPT = 'yayloyalty/storefront/launcher';
    public const STOREFRONT_STYLE  = 'yayloyalty/style/storefront-launcher';
}
