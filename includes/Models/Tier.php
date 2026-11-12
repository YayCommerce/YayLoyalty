<?php
namespace YayLoyalty\Models;

defined( 'ABSPATH' ) || exit;

/**
 * One tier of the catalogue. The multiplier is a decimal string such as "1.25", never a float,
 * so it reaches the DECIMAL(4,2) column and the wire exactly as the merchant set it. The
 * normalize_*() helpers read raw input and return null for anything a tier may not hold.
 */
final class Tier extends AbstractModel {

    public const NAME_MAX = 100;

    public const THRESHOLD_MAX = 1000000000;

    public const ENTRY_BONUS_MAX = 1000000;

    public const MULTIPLIER_MIN = '1.00';

    public const MULTIPLIER_MAX = '10.00';

    /** MULTIPLIER_MIN, MULTIPLIER_MAX and the 0.05 step, counted in hundredths. */
    public const MULTIPLIER_MIN_CENTS = 100;

    public const MULTIPLIER_MAX_CENTS = 1000;

    public const MULTIPLIER_STEP_CENTS = 5;

    /** Most live tiers a store may have. */
    public const MAX_LIVE = 10;

    private ?int $id;

    private string $name;

    /** Points earned that qualify for this tier. */
    private int $threshold;

    /** 1 for the lowest live tier; rewritten in threshold order after every catalogue change. */
    private int $tier_rank;

    private string $multiplier;

    /** Points paid once on reaching this tier. */
    private int $entry_bonus;

    /** `#RRGGBB`, uppercase. */
    private string $color;

    private string $created_at;

    private string $updated_at;

    private ?string $deleted_at;

    public function __construct(
        ?int $id,
        string $name,
        int $threshold,
        int $tier_rank,
        string $multiplier,
        int $entry_bonus,
        string $color,
        string $created_at,
        string $updated_at,
        ?string $deleted_at
    ) {
        $this->id          = $id;
        $this->name        = $name;
        $this->threshold   = $threshold;
        $this->tier_rank   = $tier_rank;
        $this->multiplier  = $multiplier;
        $this->entry_bonus = $entry_bonus;
        $this->color       = $color;
        $this->created_at  = $created_at;
        $this->updated_at  = $updated_at;
        $this->deleted_at  = $deleted_at;
    }

    public static function from_row( array $row ): self {
        return new self(
            self::cast_int( $row['id'] ?? null ),
            (string) ( $row['name'] ?? '' ),
            self::cast_int_default( $row['threshold'] ?? 0 ),
            self::cast_int_default( $row['tier_rank'] ?? 0 ),
            self::cast_decimal( $row['multiplier'] ?? null, self::MULTIPLIER_MIN ),
            self::cast_int_default( $row['entry_bonus'] ?? 0 ),
            (string) ( $row['color'] ?? '' ),
            (string) ( $row['created_at'] ?? '' ),
            (string) ( $row['updated_at'] ?? '' ),
            self::cast_datetime( $row['deleted_at'] ?? null )
        );
    }

    /** Insert payload. `id` is omitted so AUTO_INCREMENT assigns it. */
    public function to_row(): array {
        return [
            'name'        => $this->name,
            'threshold'   => $this->threshold,
            'tier_rank'   => $this->tier_rank,
            'multiplier'  => $this->multiplier,
            'entry_bonus' => $this->entry_bonus,
            'color'       => $this->color,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
            'deleted_at'  => $this->deleted_at,
        ];
    }

    public function get_format(): array {
        return [ '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' ];
    }

    /** Only TierRepository moves the rank and the deletion stamp, each in its own statement. */
    protected static function immutable_columns(): array {
        return [ 'created_at', 'tier_rank', 'deleted_at' ];
    }

    public function to_array(): array {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'threshold'   => $this->threshold,
            'tier_rank'   => $this->tier_rank,
            'multiplier'  => $this->multiplier,
            'entry_bonus' => $this->entry_bonus,
            'color'       => $this->color,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }

    /** Trimmed, plain text of 1 to NAME_MAX characters. */
    public static function normalize_name( $value ): ?string {
        if ( ! is_string( $value ) ) {
            return null;
        }

        $name   = trim( sanitize_text_field( $value ) );
        $length = mb_strlen( $name );

        return $length >= 1 && $length <= self::NAME_MAX ? $name : null;
    }

    /** A whole number from 0 to THRESHOLD_MAX. */
    public static function normalize_threshold( $value ): ?int {
        return self::whole_number_in( $value, 0, self::THRESHOLD_MAX );
    }

    /** A whole number from 0 to ENTRY_BONUS_MAX. */
    public static function normalize_entry_bonus( $value ): ?int {
        return self::whole_number_in( $value, 0, self::ENTRY_BONUS_MAX );
    }

    /**
     * A multiplier from MULTIPLIER_MIN to MULTIPLIER_MAX in steps of 0.05, as a two-decimal string.
     * Read as whole hundredths from its digits, never through a float, so "1.15" can't land on 1.149….
     */
    public static function normalize_multiplier( $value ): ?string {
        if ( is_int( $value ) ) {
            $value = (string) $value;
        } elseif ( is_float( $value ) ) {
            // A JSON number arrives as a float. Only one that is whole hundredths is read; 1.234 stays invalid.
            $hundredths = $value * 100;
            $value      = abs( $hundredths - round( $hundredths ) ) < 1e-6 ? sprintf( '%.2F', $value ) : null;
        }

        if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{1,2})(?:\.(\d{1,2}))?$/', trim( $value ), $parts ) ) {
            return null;
        }

        $cents = (int) $parts[1] * 100 + (int) str_pad( $parts[2] ?? '', 2, '0' );

        if ( $cents < self::MULTIPLIER_MIN_CENTS || $cents > self::MULTIPLIER_MAX_CENTS || 0 !== $cents % self::MULTIPLIER_STEP_CENTS ) {
            return null;
        }

        return sprintf( '%d.%02d', intdiv( $cents, 100 ), $cents % 100 );
    }

    /** `#RRGGBB`, returned uppercase. */
    public static function normalize_color( $value ): ?string {
        return is_string( $value ) && 1 === preg_match( '/^#[0-9A-Fa-f]{6}$/', $value ) ? strtoupper( $value ) : null;
    }

    /** $value as an int when it is a whole number from $min to $max, given as an int or digits. */
    private static function whole_number_in( $value, int $min, int $max ): ?int {
        if ( is_int( $value ) ) {
            $number = $value;
        } elseif ( is_string( $value ) && 1 === preg_match( '/^\d{1,10}$/', $value ) ) {
            $number = (int) $value;
        } elseif ( is_float( $value ) && floor( $value ) === $value && $value <= PHP_INT_MAX ) {
            $number = (int) $value;
        } else {
            return null;
        }

        return $number >= $min && $number <= $max ? $number : null;
    }

    public function get_id(): ?int {
        return $this->id;
    }

    public function get_name(): string {
        return $this->name;
    }

    public function get_threshold(): int {
        return $this->threshold;
    }

    public function get_tier_rank(): int {
        return $this->tier_rank;
    }

    public function get_multiplier(): string {
        return $this->multiplier;
    }

    public function get_entry_bonus(): int {
        return $this->entry_bonus;
    }

    public function get_color(): string {
        return $this->color;
    }

    public function get_created_at(): string {
        return $this->created_at;
    }

    public function get_updated_at(): string {
        return $this->updated_at;
    }

    public function get_deleted_at(): ?string {
        return $this->deleted_at;
    }

    public function is_live(): bool {
        return null === $this->deleted_at;
    }

    public function set_name( string $name ): void {
        $this->name = $name;
    }

    public function set_threshold( int $threshold ): void {
        $this->threshold = $threshold;
    }

    public function set_multiplier( string $multiplier ): void {
        $this->multiplier = $multiplier;
    }

    public function set_entry_bonus( int $entry_bonus ): void {
        $this->entry_bonus = $entry_bonus;
    }

    public function set_color( string $color ): void {
        $this->color = $color;
    }

    public function set_updated_at( string $updated_at ): void {
        $this->updated_at = $updated_at;
    }
}
