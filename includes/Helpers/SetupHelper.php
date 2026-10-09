<?php
namespace YayLoyalty\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * The setup wizard's state: one option holding the status, the step to reopen on, the unsaved draft
 * and whether the dashboard's first-run card was dismissed. The draft never touches live rows;
 * Services\SetupApplier writes it on "Turn on my program".
 */
class SetupHelper {

    public const OPTION_SETUP     = 'yayloyalty_setup';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const LAST_STEP        = 5;

    /** A decimal string, as money travels on the wire: "1", "2.50". */
    private const DECIMAL_PATTERN = '/^\d+(\.\d+)?$/';

    private const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /** The draft's groups and their keys. Anything else is refused, so the stored blob keeps one shape. */
    private const DRAFT_KEYS = [
        'earn'     => [ 'points', 'amount' ],
        'signup'   => [ 'enabled', 'points' ],
        'review'   => [ 'enabled', 'points' ],
        'reward'   => [ 'points', 'amount', 'min_points', 'max_percent' ],
        'launcher' => [ 'position', 'brand_color' ],
    ];

    /**
     * The stored state, coerced. A missing option reads as completed, never pending: a live store
     * whose install has not run on this version yet must not be sent to the wizard.
     *
     * @return array{status: string, step: int, draft: array|null, first_run_dismissed: bool}
     */
    public static function get_state(): array {
        $state = self::read_state();
        $draft = $state['draft'];

        $state['draft'] = is_array( $draft ) && null === self::draft_error( $draft ) ? $draft : null;

        return $state;
    }

    /**
     * What the admin SPA needs on first paint, localized as window.yayLoyaltyAdmin.setup.
     * Runs on every YayLoyalty admin load, so the draft is neither validated nor sent.
     */
    public static function localized_state(): array {
        $state = self::read_state();
        unset( $state['draft'] );

        return $state;
    }

    /**
     * Called by Installer::install() on every install and upgrade. Only a site that never had the plugin
     * starts pending with the program off; an existing state is never touched.
     */
    public static function on_install( bool $is_fresh ): void {
        if ( false !== get_option( self::OPTION_SETUP ) ) {
            return;
        }

        if ( ! $is_fresh ) {
            add_option( self::OPTION_SETUP, self::completed_state() );
            return;
        }

        SettingsHelper::update_settings( [ 'enabled' => false ] );
        add_option( self::OPTION_SETUP, self::pending_state() );
    }

    /**
     * Why $draft cannot be saved or applied, or null when it is valid.
     *
     * @param mixed $draft
     */
    public static function draft_error( $draft ): ?string {
        $invalid = __( 'Some setup answers are missing or invalid.', 'yayloyalty' );

        if ( ! is_array( $draft ) ) {
            return $invalid;
        }

        $expected = array_merge( [ 'points_label_singular', 'points_label_plural' ], array_keys( self::DRAFT_KEYS ) );

        if ( ! self::has_exactly( $draft, $expected ) ) {
            return $invalid;
        }

        foreach ( self::DRAFT_KEYS as $group => $keys ) {
            if ( ! is_array( $draft[ $group ] ) || ! self::has_exactly( $draft[ $group ], $keys ) ) {
                return $invalid;
            }
        }

        foreach ( [ 'points_label_singular', 'points_label_plural' ] as $key ) {
            $label = is_string( $draft[ $key ] ) ? trim( $draft[ $key ] ) : '';

            if ( '' === $label || mb_strlen( $label ) > SettingsHelper::POINTS_LABEL_MAX_LENGTH ) {
                /* translators: %d: the longest points label allowed, 30. */
                return sprintf( __( 'Points names need 1 to %d characters.', 'yayloyalty' ), SettingsHelper::POINTS_LABEL_MAX_LENGTH );
            }
        }

        $positions = DesignSchema::schema()['launcher']['position']['options'];
        $valid     = self::is_whole( $draft['earn']['points'], 1 )
            && self::is_positive_decimal( $draft['earn']['amount'] )
            && is_bool( $draft['signup']['enabled'] ) && self::is_whole( $draft['signup']['points'], 0 )
            && is_bool( $draft['review']['enabled'] ) && self::is_whole( $draft['review']['points'], 0 )
            && self::is_whole( $draft['reward']['points'], 1 )
            && self::is_positive_decimal( $draft['reward']['amount'] )
            && self::is_whole( $draft['reward']['min_points'], 0 )
            && self::is_whole( $draft['reward']['max_percent'], 1 ) && $draft['reward']['max_percent'] <= 100
            && in_array( $draft['launcher']['position'], $positions, true )
            && is_string( $draft['launcher']['brand_color'] ) && 1 === preg_match( self::COLOR_PATTERN, $draft['launcher']['brand_color'] );

        return $valid ? null : $invalid;
    }

    /**
     * Saves the step to reopen on and the draft. Callers check draft_error() first.
     *
     * @return array get_state() after the save.
     */
    public static function save_draft( int $step, array $draft ): array {
        $state          = self::read_state();
        $state['step']  = max( 1, min( self::LAST_STEP, $step ) );
        $state['draft'] = self::normalize_draft( $draft );

        update_option( self::OPTION_SETUP, $state, true );

        return $state;
    }

    public static function mark_completed(): void {
        $state           = self::read_state();
        $state['status'] = self::STATUS_COMPLETED;
        $state['step']   = self::LAST_STEP;
        $state['draft']  = null;

        update_option( self::OPTION_SETUP, $state, true );
    }

    public static function dismiss_first_run(): void {
        $state                        = self::read_state();
        $state['first_run_dismissed'] = true;

        update_option( self::OPTION_SETUP, $state, true );
    }

    /**
     * The stored state with status, step and the dismissal coerced, and the draft as stored, unvalidated.
     * A missing option reads as completed, never pending.
     *
     * @return array{status: string, step: int, draft: mixed, first_run_dismissed: bool}
     */
    private static function read_state(): array {
        $stored = get_option( self::OPTION_SETUP, null );

        if ( ! is_array( $stored ) || ! in_array( $stored['status'] ?? null, [ self::STATUS_PENDING, self::STATUS_COMPLETED ], true ) ) {
            return self::completed_state();
        }

        return [
            'status'              => $stored['status'],
            'step'                => max( 1, min( self::LAST_STEP, (int) ( $stored['step'] ?? 1 ) ) ),
            'draft'               => $stored['draft'] ?? null,
            'first_run_dismissed' => ! empty( $stored['first_run_dismissed'] ),
        ];
    }

    /** A fresh install's state: setup not started. */
    private static function pending_state(): array {
        return [
            'status'              => self::STATUS_PENDING,
            'step'                => 1,
            'draft'               => null,
            'first_run_dismissed' => false,
        ];
    }

    /** A live store's state: done, and with nothing to celebrate on the dashboard. */
    private static function completed_state(): array {
        return [
            'status'              => self::STATUS_COMPLETED,
            'step'                => self::LAST_STEP,
            'draft'               => null,
            'first_run_dismissed' => true,
        ];
    }

    /** A valid draft with its labels trimmed and its numbers cast, so the stored blob has one type per key. */
    private static function normalize_draft( array $draft ): array {
        $draft['points_label_singular'] = sanitize_text_field( trim( $draft['points_label_singular'] ) );
        $draft['points_label_plural']   = sanitize_text_field( trim( $draft['points_label_plural'] ) );

        foreach ( [ [ 'earn', 'points' ], [ 'signup', 'points' ], [ 'review', 'points' ], [ 'reward', 'points' ], [ 'reward', 'min_points' ], [ 'reward', 'max_percent' ] ] as $path ) {
            $draft[ $path[0] ][ $path[1] ] = (int) $draft[ $path[0] ][ $path[1] ];
        }

        $draft['launcher']['brand_color'] = strtolower( $draft['launcher']['brand_color'] );

        return $draft;
    }

    private static function has_exactly( array $data, array $keys ): bool {
        $actual = array_keys( $data );
        sort( $actual );
        sort( $keys );

        return $actual === $keys;
    }

    /** @param mixed $value An int, or a whole-number string from a form, of at least $min. */
    private static function is_whole( $value, int $min ): bool {
        if ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) {
            $value = (int) $value;
        }

        return is_int( $value ) && $value >= $min;
    }

    /** @param mixed $value */
    private static function is_positive_decimal( $value ): bool {
        return is_string( $value ) && 1 === preg_match( self::DECIMAL_PATTERN, $value ) && (float) $value > 0;
    }
}
