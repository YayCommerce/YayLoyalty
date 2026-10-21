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

    /** The points card: plain files with no build step. The second script fills the Cart and Checkout blocks' slot. */
    public const STOREFRONT_REDEEM_STYLE         = 'yayloyalty/style/storefront-redeem';
    public const STOREFRONT_REDEEM_SCRIPT        = 'yayloyalty/storefront/redeem';
    public const STOREFRONT_REDEEM_BLOCKS_SCRIPT = 'yayloyalty/storefront/redeem-blocks';
    /** My Account → Rewards: layout only, the theme styles the rest. */
    public const ACCOUNT_STYLE = 'yayloyalty/style/storefront-account';

    /** Product-page earning line: swaps the sentence when a variation is picked. */
    public const PRODUCT_EARN_SCRIPT = 'yayloyalty/script/storefront-product-earn';
    /** Product-page earning line: the card around it. */
    public const PRODUCT_EARN_STYLE = 'yayloyalty/style/storefront-product-earn';

    /** The storefront blocks' editor script; block.json files name it as their editorScript. */
    public const BLOCKS_EDITOR_SCRIPT = 'yayloyalty/script/blocks-editor';
}
