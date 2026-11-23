<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\Schemas\ToolsImportRouteArgs;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Tools → Import balances: a CSV's rows, checked (preview) and then written (apply) in chunks the SPA sends.
 * Each row is keyed on the import id, so a retried chunk or a re-uploaded file writes nothing twice.
 */
final class ToolsImportRestController extends AbstractRestController {

    /** Up to 9 digits, so a typo can't overflow the balance column. */
    private const POINTS_PATTERN = '/^[+-]?\d{1,9}$/D';

    private const NOTE_MAX_LENGTH = 500;

    /** @var CustomerRepository */
    private $customers;

    /** @var LedgerService */
    private $ledger_service;

    /** @var LedgerRepository */
    private $ledger;

    public function __construct( CustomerRepository $customers, LedgerService $ledger_service, LedgerRepository $ledger ) {
        $this->customers      = $customers;
        $this->ledger_service = $ledger_service;
        $this->ledger         = $ledger;
    }

    public function get_rest_base(): string {
        return 'tools';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/tools/import/preview',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => fn( $request ) => $this->exec_read( [ $this, 'preview' ], $request ),
                'permission_callback' => $this->requires( Capabilities::RUN_TOOLS ),
                'args'                => ToolsImportRouteArgs::preview(),
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/tools/import/apply',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => fn( $request ) => $this->exec_self_committing( [ $this, 'apply' ], $request ),
                'permission_callback' => $this->requires( Capabilities::RUN_TOOLS ),
                'args'                => ToolsImportRouteArgs::apply(),
            ]
        );
    }

    /** @return array{rows: array[]} One result per row: ready, already_imported, imported_before (this customer has a row from another import) or error, with the balance before and after. */
    public function preview( \WP_REST_Request $request ): array {
        $import_id = (int) $request['import_id'];
        $results   = [];

        foreach ( (array) $request['rows'] as $row ) {
            $checked = $this->check( $row );

            if ( isset( $checked['reason'] ) ) {
                $results[] = $checked;
                continue;
            }

            $customer = $this->customers->find_by_user_id( $checked['user_id'] );
            $balance  = null !== $customer ? $customer->get_balance() : 0;
            $imported = null !== $this->ledger->find_by_idempotency_key( IdempotencyKey::for_import( $checked['user_id'], $import_id ) );

            if ( ! $imported && LedgerService::import_would_go_negative( $balance, $checked['points'] ) ) {
                $results[] = self::error( $checked['row'], LedgerService::REASON_WOULD_GO_NEGATIVE );
                continue;
            }

            $earlier = $imported ? null : $this->ledger->latest_other_import( $checked['user_id'], $import_id );

            $results[] = [
                'row'           => $checked['row'],
                'status'        => $imported ? 'already_imported' : ( null !== $earlier ? 'imported_before' : 'ready' ),
                'imported_at'   => null !== $earlier ? $earlier->get_created_at() : null,
                'balance'       => $balance,
                'balance_after' => $imported ? $balance : $balance + $checked['points'],
            ];
        }

        return [ 'rows' => $results ];
    }

    /** @return array{rows: array[]} One result per row: imported, duplicate, skipped or error. A failed row never undoes the others. */
    public function apply( \WP_REST_Request $request ): array {
        $import_id = (int) $request['import_id'];
        /* translators: %s: the uploaded CSV file's name. */
        $default_note = sprintf( __( 'Imported from %s', 'yayloyalty' ), '' !== $request['file_name'] ? $request['file_name'] : 'CSV' );
        $results      = [];

        foreach ( (array) $request['rows'] as $row ) {
            $checked = $this->check( $row );

            if ( isset( $checked['reason'] ) ) {
                $results[] = $checked;
                continue;
            }

            $note = mb_substr( sanitize_text_field( (string) ( $row['note'] ?? '' ) ), 0, self::NOTE_MAX_LENGTH );

            try {
                $outcome = $this->ledger_service->import( $checked['user_id'], $checked['points'], $import_id, '' !== $note ? $note : $default_note, get_current_user_id() );
            } catch ( \Throwable $e ) {
                Log::error( 'ToolsImport', sprintf( 'import %d row %d failed: %s', $import_id, $checked['row'], $e->getMessage() ) );
                $results[] = self::error( $checked['row'], 'failed' );
                continue;
            }

            $results[] = [
                'row'    => $checked['row'],
                'status' => self::status_of( $outcome ),
                'reason' => Outcome::SKIPPED === $outcome->get_status() ? $outcome->get_reason() : null,
            ];
        }

        return [ 'rows' => $results ];
    }

    /**
     * The row's customer and points, or an error row.
     *
     * @param mixed $row One `rows` item.
     * @return array{row: int, user_id: int, points: int}|array{row: int, status: string, reason: string}
     */
    private function check( $row ): array {
        $row    = (array) $row;
        $number = (int) ( $row['row'] ?? 0 );
        $points = trim( (string) ( $row['points'] ?? '' ) );
        $user   = get_user_by( 'email', sanitize_email( (string) ( $row['email'] ?? '' ) ) );

        if ( ! $user instanceof \WP_User ) {
            return self::error( $number, 'unknown_email' );
        }

        if ( 1 !== preg_match( self::POINTS_PATTERN, $points ) || 0 === (int) $points ) {
            return self::error( $number, 'invalid_points' );
        }

        return [
            'row'     => $number,
            'user_id' => (int) $user->ID,
            'points'  => (int) $points,
        ];
    }

    /** @return array{row: int, status: string, reason: string} */
    private static function error( int $row, string $reason ): array {
        return [
            'row'    => $row,
            'status' => 'error',
            'reason' => $reason,
        ];
    }

    private static function status_of( Outcome $outcome ): string {
        switch ( $outcome->get_status() ) {
            case Outcome::AWARDED:
                return 'imported';
            case Outcome::DUPLICATE:
                return 'duplicate';
            default:
                return 'skipped';
        }
    }
}
