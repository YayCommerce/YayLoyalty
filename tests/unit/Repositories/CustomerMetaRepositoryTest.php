<?php
namespace YayLoyalty\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Repositories\CustomerMetaRepository;

/**
 * `paginate_activity()`'s empty-`event_types` short-circuit is the one
 * behaviour here reachable without a database: it returns before touching
 * `$wpdb`, so this runs as a pure unit test (`AbstractRepository` has no
 * constructor).
 */
final class CustomerMetaRepositoryTest extends TestCase {

    public function test_empty_event_types_returns_an_empty_page_without_reaching_the_database(): void {
        $repository = new CustomerMetaRepository();

        $result = $repository->paginate_activity( 1, [ 'event_types' => [] ] );

        $this->assertSame( [], $result['items'] );
        $this->assertSame( 0, $result['total'] );
    }
}
