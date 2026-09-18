<?php
namespace YayLoyalty\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use YayLoyalty\Utils\PointsMath;

/**
 * The cross-language contract. `tests/fixtures/points-math-vectors.json` is
 * keyed by PointsMath method name; `apps/admin/tests/points-math.vectors.test.ts`
 * runs the same file under Vitest against the admin SPA's `lib/points-math`.
 * A vector added on one side must pass on the other, and a method added
 * without vectors fails here — so the two implementations cannot drift
 * silently.
 *
 * Expected values were produced by PHP (the source of truth). Only inputs
 * every supported PHP version (7.4+) rounds identically belong in the file:
 * round() pre-rounded to 15 significant digits up to PHP 8.3 and stopped in
 * 8.4, so a value a hair off a half — 0.49999999999999994, 1.4999999999999998 —
 * rounds differently between releases and is deliberately absent. Every
 * kept case lands on an exact binary half (or nowhere near one) after the
 * arithmetic, so both algorithms agree.
 */
final class PointsMathVectorsTest extends TestCase {

    private const FIXTURE = __DIR__ . '/../../fixtures/points-math-vectors.json';

    /**
     * @return array<string, array<int, array{inputs: array<int, mixed>, expected: mixed}>>
     */
    private function vectors(): array {
        // Plain PHP, no WordPress: the WP-flavoured alternatives do not exist here.
        $decoded = json_decode( (string) file_get_contents( self::FIXTURE ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        $this->assertIsArray( $decoded, 'points-math-vectors.json must decode to an object' );

        return $decoded;
    }

    /**
     * @return string[]
     */
    private function public_static_methods(): array {
        $names = [];

        // getMethods() ORs its flags, so filter static then keep only public.
        foreach ( ( new ReflectionClass( PointsMath::class ) )->getMethods( ReflectionMethod::IS_STATIC ) as $method ) {
            if ( $method->isPublic() ) {
                $names[] = $method->getName();
            }
        }

        sort( $names );

        return $names;
    }

    public function test_every_vector_key_has_an_implementation(): void {
        foreach ( array_keys( $this->vectors() ) as $name ) {
            $this->assertTrue( method_exists( PointsMath::class, $name ), "PointsMath::{$name}() has vectors but no implementation" );
        }
    }

    public function test_every_public_method_has_vectors(): void {
        $keys = array_keys( $this->vectors() );
        sort( $keys );

        $this->assertSame( $this->public_static_methods(), $keys, 'Every public static PointsMath method needs a key in points-math-vectors.json' );
    }

    public function test_every_vector_passes(): void {
        foreach ( $this->vectors() as $name => $cases ) {
            $this->assertNotEmpty( $cases, "{$name} has no vectors" );

            foreach ( $cases as $case ) {
                $label  = $name . '(' . json_encode( $case['inputs'] ) . ')'; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
                $actual = PointsMath::$name( ...$case['inputs'] );

                if ( null === $case['expected'] ) {
                    $this->assertNull( $actual, $label );
                    continue;
                }

                $this->assertSame( $case['expected'], $actual, $label );
            }
        }
    }
}
