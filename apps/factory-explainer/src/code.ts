// Excerpts of the plugin's PHP, trimmed by hand for the bubble's Code view; `…` marks a cut. Keep them in step with includes/.

export const ON_STAGEABLE = [
  '// OrderListener::route(): pending stages, never awards',
  'case \'pending\':',
  '    $this->on_stageable( $order );',
  '…',
  'public function on_stageable( WC_Order $order ): void {',
  '    $order_id = (int) $order->get_id();',
  '    $ctx      = ContextFactory::from_order( $order );',
  '    $result   = $this->service->preview( PURCHASE, $ctx );',
  '    if ( $result->is_awarded() ) {',
  '        $this->pending->stage( $order_id, $user_id, $points );',
  '    }',
  '}',
];

export const PREVIEW_EVALUATE = [
  '// EarningService::preview() → PointsEngine::evaluate()',
  'public function evaluate( string $trigger, Context $ctx ): Result {',
  '    $handler    = $this->registry->get( $trigger );',
  '    $candidates = $this->candidates( $trigger, $ctx );',
  '    $campaign   = $this->pick( $handler, $candidates, $ctx );',
  '…',
  '    $breakdown = $handler->compute_points( $campaign, $ctx );',
  '    $points    = $this->caps->apply( $breakdown->get_points(), $campaign );',
  '…',
  '    return Result::awarded( $points, $campaign, … );',
  '}',
];

export const GATE_CHECK = [
  'public function check( string $trigger, Context $ctx ): ?string {',
  '    if ( ! self::program_enabled() ) {',
  '        return self::REASON_PROGRAM_PAUSED;',
  '    }',
  '    $user_id = $ctx->get_user_id();',
  '    if ( null === $user_id ) {',
  '        return self::REASON_NO_CUSTOMER;',
  '    }',
  '    if ( get_user_meta( $user_id, self::META_OPTED_OUT, true ) ) {',
  '        return self::REASON_OPTED_OUT;',
  '    }',
  '    if ( array_intersect( $roles, $settings[\'excluded_roles\'] ) ) {',
  '        return self::REASON_EXCLUDED_ROLE;',
  '    }',
  '    return null;',
  '}',
];

export const STAGE = [
  'public function stage( int $order_id, ?int $user_id, int $points ): bool {',
  '    $order = wc_get_order( $order_id );',
  '…',
  '    $order->update_meta_data( self::META_PENDING, $points );',
  '    $this->save_meta( $order );',
  '    if ( null !== $user_id ) {',
  '        $this->customers->ensure_row( $user_id );',
  '        $this->customers->apply_pending_delta( $user_id, $points );',
  '    }',
  '    return true; // no LedgerService call: nothing is earned yet',
  '}',
];

export const ON_REGISTERED = [
  'public function on_user_register( int $user_id ): void {',
  '    ListenerGuard::run( …, fn() => $this->on_registered( $user_id ) );',
  '}',
  '…',
  'public function on_registered( int $user_id ): void {',
  '    if ( ! self::is_self_registration() ) {',
  '        return;',
  '    }',
  '    $user = get_userdata( $user_id );',
  '…',
  '    $ctx     = ContextFactory::from_user( $user_id, $user->user_registered );',
  '    $outcome = $this->service->award( CampaignTrigger::SIGNUP, $ctx );',
  '}',
];

export const AWARD = [
  'public function award( string $trigger, Context $ctx ): Outcome {',
  '    $result = $this->preview( $trigger, $ctx ); // gates, then evaluate()',
  '    if ( ! $result->is_awarded() ) {',
  '        return Outcome::skipped( $result->get_reason(), $result );',
  '    }',
  '    $outcome = Transaction::run( function () use ( … ): Outcome {',
  '        $outcome = $this->ledger->earn( $this->draft_from( $result, … ), … );',
  '…',
  '        return $outcome;',
  '    } );',
  '…',
  '}',
];

export const PREVIEW = [
  'public function preview( string $trigger, Context $ctx ): Result {',
  '    $reason = $this->blocked_reason( $trigger, $ctx );',
  '    if ( null !== $reason ) {',
  '        return Result::none( $reason );',
  '    }',
  '    return $this->engine->evaluate( $trigger, $ctx );',
  '}',
  '…',
  'private static function first_reason( array $gates, … ): ?string {',
  '    foreach ( $gates as $gate ) {',
  '        $reason = $gate->check( $trigger, $ctx );',
  '…',
];

export const EARN = [
  '// LedgerService::earn() → write_keyed()',
  'return $this->under_lock( $draft->get_user_id(), function ( Customer $customer ) … {',
  '    $existing = $this->ledger->find_by_idempotency_key( $key );',
  '    if ( null !== $existing ) {',
  '        return Outcome::duplicate( $existing );',
  '    }',
  '…',
  '    $persisted = $this->append( $draft, $customer, true );',
  '    return Outcome::awarded( $persisted );',
  '} );',
];

export const APPLY_DELTA = [
  '// LedgerService::append(), still under the customer lock',
  '$balance_after = $customer->get_balance() + $draft->get_points();',
  '$insert_id     = $this->ledger->insert_unless_duplicate( $to_insert );',
  '$this->apply_aggregates( $draft );',
  '…',
  '// CustomerRepository::apply_delta()',
  'UPDATE customers SET',
  '    balance = balance + %d,',
  '    lifetime_earned = lifetime_earned + %d,',
  '    updated_at = %s',
  'WHERE user_id = %d',
  '// Transaction::run() commits the row and the balance together',
];

export const ON_AWARDABLE = [
  '// OrderListener::route()',
  'if ( OrderStatus::AWARD === $to ) {',
  '    $this->on_awardable( $order );',
  '}',
  '…',
  'public function on_awardable( WC_Order $order ): void {',
  '    $triggers = apply_filters( \'yayloyalty_order_award_triggers\', [ PURCHASE ], $order );',
  '    foreach ( $triggers as $trigger ) {',
  '        $outcome = $this->service->award( $trigger, ContextFactory::from_order( $order ) );',
  '        $this->note( $order, $outcome );',
  '    }',
  '    $this->pending->release( $order_id );',
  '}',
];

export const RELEASE = [
  '// EarningService::award(), inside the earn\'s transaction',
  'if ( null !== $order_id && Outcome::AWARDED === $outcome->get_status() ) {',
  '    $this->pending->release( $order_id );',
  '    $this->pending->mark_awarded( $order_id, $outcome->get_entry() );',
  '}',
  '…',
  '// PendingPoints::release()',
  '$order->update_meta_data( self::META_RELEASED, Dates::now() );',
  '$this->customers->apply_pending_delta( $user_id, -$pending );',
  '…',
  '// PendingPoints::mark_awarded()',
  '$order->update_meta_data( self::META_EARNED, $earned + $entry->get_points() );',
];

export const ON_COMMENT_POST = [
  'public function on_comment_post( int $comment_id, $approved ): void {',
  '    if ( 1 !== (int) $approved ) {',
  '        return;',
  '    }',
  '    $comment = get_comment( $comment_id );',
  '    if ( ! self::is_product_review( $comment ) ) {',
  '        return;',
  '    }',
  '    $this->on_approved( $comment );',
  '}',
  '…',
  'public function on_approved( WP_Comment $comment ): void {',
  '    $ctx     = ContextFactory::from_comment( $comment );',
  '    $outcome = $this->service->award( CampaignTrigger::REVIEW, $ctx );',
  '}',
];

export const SKIPPED = [
  '// EarningService::award()',
  '$result = $this->preview( $trigger, $ctx ); // Result::none( \'opted_out\' )',
  'if ( ! $result->is_awarded() ) {',
  '    return Outcome::skipped( $result->get_reason(), $result );',
  '}',
  '// never reached: no Transaction::run(), no LedgerService::earn()',
  '$outcome = Transaction::run( … );',
];

export const ADJUST = [
  'public function adjust( int $user_id, int $points, string $note, int $actor_id, bool $adjust_lifetime = true ): LedgerEntry {',
  '    $draft = new LedgerEntry(',
  '        null, $user_id, $points, 0,',
  '        $adjust_lifetime ? LedgerType::ADJUST : LedgerType::CORRECTION,',
  '        LedgerSource::MANUAL,',
  '…',
  '        $note,',
  '        $actor_id > 0 ? $actor_id : null,',
  '        Dates::now()',
  '    );',
  '    return $this->record( $draft ); // under_lock() → append()',
  '}',
];

export const ON_STATUS_REFUNDED = [
  '// OrderListener::route()',
  'case \'refunded\':',
  '    $this->reversals->on_status_refunded( $order );',
  '…',
  'public function on_status_refunded( WC_Order $order ): void {',
  '    $this->pending->release( (int) $order->get_id() );',
  '    $outcomes = $this->reversal->refunded_status( $order );',
  '    $this->note_reversed( $order, $outcomes, __( \'order refunded\' ) );',
  '}',
];

export const REFUND_EARN = [
  'public function refund_earn( LedgerEntry $earn, int $owed_total, … ): Outcome {',
  '    $draft = $this->reversal_draft( $earn, LedgerType::REFUND_EARN, 0, … );',
  '    return $this->write_keyed( $draft, function () use ( $earn, $owed_total ): int {',
  '        return -max( 0, min( $owed_total, $earn->get_points() ) - $refunded );',
  '    } );',
  '}',
  '…',
  '// write_keyed(), under the customer lock',
  '$existing = $this->ledger->find_by_idempotency_key( $key );',
  'if ( null !== $existing ) {',
  '    return Outcome::duplicate( $existing );',
  '}',
  '$persisted = $this->append( $draft->with_points( $points ), $customer, true );',
];
