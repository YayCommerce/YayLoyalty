<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/** Who performed a write: a staff member (`actor_id > 0`), the system, or the customer themselves. */
final class ActorKind {

    public const STAFF     = 'staff';
    public const AUTOMATIC = 'automatic';
    public const CUSTOMER  = 'customer';

    /** @var string[] */
    public const ALL = [
        self::STAFF,
        self::AUTOMATIC,
        self::CUSTOMER,
    ];
}
