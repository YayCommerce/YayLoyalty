<?php
namespace YayLoyalty\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * TEMPORARY DEMO DATA — NOT A REAL QUERY.
 *
 * Backs `CustomerRestController::get_items()` (GET /yayloyalty/v1/customers).
 * `CustomerRepository` has no list/paginate method yet, so there is no real
 * Customers list to serve — this is the same fixed rows every time, shaped
 * to match `apps/admin/src/pages/customers/customers-page.mock-data.ts`'s
 * `MockCustomer`.
 *
 * 30 rows: enough to exercise the admin list's search, every filter bucket
 * (including boundary balances — see CustomerDemoRowsSummit::rows() — and
 * expiries inside 30/90 days), sort, and real pagination (10/page → 3 pages)
 * without a live backend.
 *
 * Split by tier into `CustomerDemoRowsAlpine` / `CustomerDemoRowsSummit` /
 * `CustomerDemoRowsExplorer` (this file only merges them) to keep every file
 * under the project's 200-line cap — it is table literals, not behaviour.
 *
 * DELETE this whole class and its 3 row files, and the route/method that
 * call it, once `CustomerRepository` gains a real
 * `paginate_filtered()`-style method.
 */
final class CustomerDemoRows {

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array {
        return array_merge(
            CustomerDemoRowsAlpine::rows(),
            CustomerDemoRowsSummit::rows(),
            CustomerDemoRowsExplorer::rows()
        );
    }
}
