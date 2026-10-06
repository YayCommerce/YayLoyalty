<?php
namespace YayLoyalty\Utils;

/** One shared instance per class, for the boot classes only. */
trait SingletonTrait {
    protected static $instance = null;

    protected function __construct() { }

    public static function get_instance( ...$args ) {
        if ( ! isset( static::$instance ) ) {
            static::$instance = new static( ...$args );
        }

        return static::$instance;
    }

    protected function __clone() { }

    public function __wakeup() {
        throw new \Exception( 'Cannot unserialize a singleton.' );
    }
}
