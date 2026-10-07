<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/** The values each customer-list filter accepts. */
final class CustomerFilters {

    public const BALANCE_RANGES = [ 'any', 'negative', '0-1000', '1000-10000', '10000-plus' ];

    public const EXPIRY_RANGES = [ 'off', '30', '90', 'none' ];
}
