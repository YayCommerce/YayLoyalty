<?php
namespace YayLoyalty\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Repositories\CustomerMetaRepository;

/**
 * `build_in_placeholders()` is the one piece of `CustomerMetaRepository`'s
 * SQL layer this suite can unit test without WordPress/$wpdb/a database
 * (AbstractRepository has no constructor, so the repository instantiates
 * directly; the method itself never touches $wpdb). Reached via reflection
 * since it is intentionally private — no query-building surface, callers
 * only see `count_activity()`/`paginate_activity()`'s public short-circuit
 * behaviour for an empty list.
 */
final class CustomerMetaRepositoryPlaceholdersTest extends TestCase {

    private function build_in_placeholders( array $values ): string {
        $repository = new CustomerMetaRepository();
        $method     = new \ReflectionMethod( CustomerMetaRepository::class, 'build_in_placeholders' );
        $method->setAccessible( true );

        return $method->invoke( $repository, $values );
    }

    public function test_placeholder_count_matches_input_count(): void {
        $this->assertSame( '', $this->build_in_placeholders( [] ) );
        $this->assertSame( '%s', $this->build_in_placeholders( [ 'account_created' ] ) );
        $this->assertSame(
            '%s,%s,%s',
            $this->build_in_placeholders( [ 'account_created', 'points_adjusted', 'account_deleted' ] )
        );
    }

    public function test_empty_event_types_never_reaches_in_clause_construction(): void {
        $repository = new CustomerMetaRepository();

        $this->assertSame( 0, $repository->count_activity( 1, [] ) );

        $result = $repository->paginate_activity( 1, [ 'event_types' => [] ] );

        $this->assertSame( [], $result['items'] );
        $this->assertSame( 0, $result['total'] );
    }
}
