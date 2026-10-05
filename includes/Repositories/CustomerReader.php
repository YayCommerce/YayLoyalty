<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Models\Customer;

defined( 'ABSPATH' ) || exit;

/**
 * The read-only face of the customers table, for code that may look a customer up but must never write one.
 * The redemption quote path depends on this, not on CustomerRepository, so it cannot reach apply_delta()
 * or ensure_row().
 */
interface CustomerReader {

    public function find_by_user_id( int $user_id ): ?Customer;
}
