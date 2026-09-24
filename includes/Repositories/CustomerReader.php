<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Models\Customer;

defined( 'ABSPATH' ) || exit;

/**
 * The read-only face of the customers table, for code that must be able to
 * look a customer up but must never be able to write one — the redemption
 * quote path depends on this, not on CustomerRepository, so holding it
 * grants no apply_delta() / ensure_row() by construction.
 */
interface CustomerReader {

    public function find_by_user_id( int $user_id ): ?Customer;
}
