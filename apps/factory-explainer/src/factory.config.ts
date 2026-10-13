// The factory's layout and story. World units are the 2D page's floor units ÷ 40: x is floor x, z is floor y, y is up.
// Code names are copied from the 2D explainer, which was checked against the PHP; keep them exact.

export type StationId = 'order-listener' | 'signup-listener' | 'review-listener' | 'reversal-listener' | 'earning-service' | 'program-gate' | 'pending-points' | 'ledger-service' | 'vault' | 'redemption' | 'expiry' | 'tiers';
export type StationType = 'intake' | 'scoring' | 'gate' | 'ledger' | 'warehouse' | 'vault' | 'return' | 'unbuilt';
export type PackageId = 'alice' | 'bob' | 'cara' | 'admin';
export type Vec2 = [ x: number, z: number ];
export type Endpoint = StationId | `dock-${ PackageId }` | 'reject-bin';

export interface Station {
  id: StationId;
  type: StationType;
  role: string;
  label: string;
  description: string;
  summary: string;
  file: string;
  hooks: string[];
  methods: string[];
  inputs: string[];
  outputs: string[];
  position: Vec2;
  size: [ w: number, d: number, h: number ];
  labelHeight: number; // label anchor above the top face's back corner; enough to clear parts taller than `size`
  labelPlace?: 'start' | 'below'; // 'start': the label runs rightwards from its anchor; 'below': it hangs under the machine's front corner. Both clear neighbouring machines
  built: boolean;
}

export interface Conveyor { id: string; from: Endpoint; to: Endpoint; via: Vec2[]; kind: 'main' | 'reject' | 'return'; }
export interface Actor { id: PackageId; name: string; color: string; home: Vec2; dock: Vec2; console?: boolean; }
export interface Card { fn: string; lines: string[]; out: string; }

/** settle parks a box in a row on the front of the vault's plinth, so the three deposits stay visible. */
export interface Step {
  label: string;
  card: Card;
  at?: StationId;
  pkg?: PackageId;
  conveyor?: string;
  pulse?: StationId;
  gate?: 'lift' | 'deny';
  press?: boolean;
  settle?: [ x: number, y: number, z: number ];
  tint?: 'rejected' | 'refund';
}

export interface Scene { id: `scene-${ 1 | 2 | 3 | 4 | 5 | 6 }`; caption: string; show?: PackageId; steps: Step[]; }
export interface Box { id: PackageId; event: 'ORDER' | 'SIGNUP' | 'REVIEW' | 'MANUAL'; points: string; color: 'earn' | 'adjust'; }
export interface Prop { kind: 'pallet' | 'cone' | 'forklift' | 'cable-tray'; position: Vec2; rotation: number; }

export const BELT_Y = 0.25;

/** Seconds, and world units a second for SPEED. */
export const TIMING = { INTRO: 1.6, WALK: 2.0, HOP: 2.4, SPEED: 5.5, WORK: 3.4, HOLD: 2.4 } as const;

export const STATIONS: Station[] = [
  {
    id: 'order-listener', type: 'intake', role: 'Intake', label: 'OrderListener', built: true,
    description: 'Routes WooCommerce order statuses to staging, awarding or reversal.',
    file: 'includes/Engine/Earning/Listeners/OrderListener.php',
    hooks: [ 'woocommerce_new_order', 'woocommerce_order_status_changed' ],
    methods: [ 'on_order_created()  ← woocommerce_new_order', 'on_status_changed()  ← woocommerce_order_status_changed', 'on_stageable()  (pending, on-hold)', 'on_awardable()  (OrderStatus::AWARD = completed)' ],
    inputs: [ 'a WooCommerce order and its new status' ],
    outputs: [ 'EarningService::preview() → PendingPoints::stage() on pending or on-hold', 'EarningService::award() per trigger in yayloyalty_order_award_triggers on completed', 'OrderReversalListener on cancelled, trash or refunded' ],
    summary: 'Routes WooCommerce order statuses. pending and on-hold preview the purchase and stage its points; completed awards them, once per trigger in yayloyalty_order_award_triggers, then releases the staged figure. cancelled, trash and refunded go to OrderReversalListener.',
    position: [ 5.75, 2.75 ], size: [ 2.75, 2, 1.15 ], labelHeight: 0.45,
  },
  {
    id: 'signup-listener', type: 'intake', role: 'Intake', label: 'SignupListener', built: true,
    description: 'Awards the SIGNUP trigger when an account is registered.',
    file: 'includes/Engine/Earning/Listeners/SignupListener.php',
    hooks: [ 'user_register' ],
    methods: [ 'on_user_register()  ← user_register' ],
    inputs: [ 'the new user id' ],
    outputs: [ 'EarningService::award( SIGNUP )' ],
    summary: 'Awards the SIGNUP trigger when a new account is registered. No pending stage: the points land at once.',
    position: [ 5.75, 5.75 ], size: [ 2.75, 2, 1.15 ], labelHeight: 0.45,
  },
  {
    id: 'review-listener', type: 'intake', role: 'Intake', label: 'ReviewListener', built: true,
    description: 'Awards the REVIEW trigger for an approved product review.',
    file: 'includes/Engine/Earning/Listeners/ReviewListener.php',
    hooks: [ 'comment_post', 'transition_comment_status' ],
    methods: [ 'on_comment_post()  ← comment_post', 'on_comment_transition()  ← transition_comment_status' ],
    inputs: [ 'a product review, posted approved or approved later' ],
    outputs: [ 'EarningService::award( REVIEW )' ],
    summary: 'Awards the REVIEW trigger for a product review, either when it is posted approved or on a later approval. Both paths build the same Context.',
    position: [ 5.75, 8.75 ], size: [ 2.75, 2, 1.15 ], labelHeight: 0.45,
  },
  {
    id: 'reversal-listener', type: 'return', role: 'Return', label: 'OrderReversalListener', built: true,
    description: 'Takes points back when an order is cancelled, trashed or refunded.',
    file: 'includes/Engine/Earning/Listeners/OrderReversalListener.php',
    hooks: [ 'woocommerce_order_refunded', 'woocommerce_refund_deleted', 'woocommerce_trash_order', 'trashed_post' ],
    methods: [ 'on_cancelled()', 'on_status_refunded()', 'on_order_refunded()  ← woocommerce_order_refunded', 'on_refund_deleted()  ← woocommerce_refund_deleted', 'on_order_trashed()  ← woocommerce_trash_order, trashed_post', 'Reversal::full_order(), refund(), refunded_status()' ],
    inputs: [ 'a cancelled, trashed or refunded order' ],
    outputs: [ 'Reversal → LedgerService::refund_earn() (REFUND_EARN)' ],
    summary: 'Takes points back when an order is cancelled, trashed or refunded. It clears any pending figure, then Reversal asks LedgerService::refund_earn() to write a REFUND_EARN row against the original earn.',
    position: [ 19.5, 11.75 ], size: [ 3.5, 2, 1 ], labelHeight: 0.25,
  },
  {
    id: 'earning-service', type: 'scoring', role: 'Scoring', label: 'EarningService', built: true,
    description: 'Runs the gates, then PointsEngine scores the trigger.',
    file: 'includes/Engine/Earning/EarningService.php',
    hooks: [ 'filter yayloyalty_award_points', 'action yayloyalty_points_awarded' ],
    methods: [ 'preview( $trigger, Context ): Result  (writes nothing)', 'award( $trigger, Context ): Outcome', 'PointsEngine::evaluate( $trigger, Context )', 'filter yayloyalty_award_points', 'action yayloyalty_points_awarded' ],
    inputs: [ 'a trigger (PURCHASE, SIGNUP, REVIEW) and its Context' ],
    outputs: [ 'preview(): a Result', 'award(): an Outcome, after LedgerService::earn()' ],
    summary: 'Runs the earning gates, then PointsEngine::evaluate() scores the trigger (PURCHASE, SIGNUP, REVIEW) against the active campaigns. preview() stops there; award() hands the result to LedgerService::earn().',
    position: [ 10.5, 5.75 ], size: [ 3.25, 2.75, 1.6 ], labelHeight: 0.25, labelPlace: 'start',
  },
  {
    id: 'program-gate', type: 'gate', role: 'Gate', label: 'ProgramGate', built: true,
    description: 'Checks program on, a customer, not opted out, role not excluded.',
    file: 'includes/Engine/Earning/ProgramGate.php',
    hooks: [],
    methods: [ 'check( $trigger, Context ): ?string', 'program_enabled(): bool' ],
    inputs: [ 'a trigger and its Context' ],
    outputs: [ 'null to pass, or the first failing reason: program_paused, no_customer, opted_out, excluded_role' ],
    summary: 'Lite\'s gate, checked in order: program on, a customer, not opted out, role not excluded. The first failing rule wins and the package is rejected with that reason.',
    position: [ 15, 5.75 ], size: [ 2, 2.25, 0.35 ], labelHeight: 1.2,
  },
  {
    id: 'pending-points', type: 'warehouse', role: 'Warehouse', label: 'PendingPoints', built: true,
    description: 'Holds an order\'s points while it is pending or on-hold.',
    file: 'includes/Engine/Earning/PendingPoints.php',
    hooks: [],
    methods: [ 'stage( $order_id, $user_id, $points )', 'release( $order_id )', 'mark_awarded( $order_id, LedgerEntry )', 'order meta _yayloyalty_points_pending, _yayloyalty_points_released, _yayloyalty_points_earned', 'customers.pending_points' ],
    inputs: [ 'an order id, its customer and the previewed points' ],
    outputs: [ 'order meta _yayloyalty_points_pending', 'customers.pending_points' ],
    summary: 'Holds an order\'s points while it is pending or on-hold: the money has not arrived. Pending is not a ledger state; it lives in order meta, with the total cached in customers.pending_points.',
    position: [ 19.5, 2.5 ], size: [ 3, 2.25, 1.4 ], labelHeight: 0.25,
  },
  {
    id: 'ledger-service', type: 'ledger', role: 'Ledger press', label: 'LedgerService', built: true,
    description: 'The only writer of ledger rows and balances.',
    file: 'includes/Services/LedgerService.php',
    hooks: [ 'action yayloyalty_ledger_recorded' ],
    methods: [ 'earn( LedgerEntry ): Outcome  → EARN', 'adjust( ... ): LedgerEntry  → ADJUST', 'refund_earn( ... ): Outcome  → REFUND_EARN', 'restore_refund( ... ): Outcome', 'action yayloyalty_ledger_recorded' ],
    inputs: [ 'a draft LedgerEntry, an admin adjustment, or the EARN row to reverse' ],
    outputs: [ 'one ledger row with its balance_after', 'the customer\'s totals, in the same transaction' ],
    summary: 'The only writer of ledger rows and balances. In one transaction it locks the customer row, appends the row with its running balance_after, and updates the customer\'s totals. System rows carry an idempotency key, so a replayed event cannot award twice.',
    position: [ 19.5, 5.75 ], size: [ 3, 2.5, 1.6 ], labelHeight: 0.6,
  },
  {
    id: 'vault', type: 'vault', role: 'Vault', label: 'customers', built: true,
    description: 'The customer\'s cached totals; only LedgerService writes them.',
    file: 'includes/Database/Schema/CustomersTable.php',
    hooks: [],
    methods: [ 'balance', 'lifetime_earned', 'lifetime_redeemed', 'pending_points' ],
    inputs: [ 'LedgerService writes, inside its transaction' ],
    outputs: [ 'balance, lifetime_earned, lifetime_redeemed, pending_points' ],
    summary: 'The customer\'s cached totals. Only LedgerService writes them, in the same transaction as the ledger row.',
    position: [ 24.25, 5.75 ], size: [ 3.75, 3.75, 2.1 ], labelHeight: 0.25, labelPlace: 'start',
  },
  {
    id: 'redemption', type: 'unbuilt', role: 'Spend', label: 'Redemption', built: false,
    description: 'Not built yet: no package can be spent.',
    file: 'includes/Engine/Redemption/RedemptionService.php',
    hooks: [],
    methods: [ 'RedemptionService::is_available()  returns false', 'LedgerService::redeem()  throws "Not implemented"', 'LedgerType::REDEEM, REFUND_REDEEM' ],
    inputs: [],
    outputs: [],
    summary: 'Not built yet. The redemption engine is scaffolding marked TODO(redemption): is_available() returns false and LedgerService::redeem() throws, so no package can be spent.',
    position: [ 29, 2.5 ], size: [ 2.75, 2.25, 1.4 ], labelHeight: 0.25, labelPlace: 'below',
  },
  {
    id: 'expiry', type: 'unbuilt', role: 'Expire', label: 'Expiry', built: false,
    description: 'Not built yet: nothing writes EXPIRE rows.',
    file: 'includes/Services/LedgerService.php',
    hooks: [],
    methods: [ 'LedgerType::EXPIRE  (no writer)', 'customers.expires_at' ],
    inputs: [],
    outputs: [],
    summary: 'Not built yet. Nothing writes EXPIRE rows: LedgerService notes that expiry needs the lots table, which is designed but not built.',
    position: [ 29, 5.75 ], size: [ 2.75, 2.25, 1.4 ], labelHeight: 0.25, labelPlace: 'below',
  },
  {
    id: 'tiers', type: 'unbuilt', role: 'Tiers', label: 'VIP tiers', built: false,
    description: 'Not built yet: only the columns exist.',
    file: 'includes/Database/Schema/CustomersTable.php',
    hooks: [],
    methods: [ 'customers.tier_id', 'customers.tier_evaluated_at', 'customers.tier_progress_value' ],
    inputs: [],
    outputs: [],
    summary: 'Not built yet. Only the columns exist, declared ahead so the feature lands without a migration; CampaignConditionType::TIER is unavailable.',
    position: [ 29, 9 ], size: [ 2.75, 2.25, 1.4 ], labelHeight: 0.25, labelPlace: 'below',
  },
];

export const ACTORS: Actor[] = [
  { id: 'alice', name: 'Alice', color: '#f59e0b', dock: [ 1.5, 2.75 ], home: [ 0, 2.75 ] },
  { id: 'bob', name: 'Bob', color: '#3b82f6', dock: [ 1.5, 5.75 ], home: [ 0, 5.75 ] },
  { id: 'cara', name: 'Cara', color: '#ec4899', dock: [ 1.5, 8.75 ], home: [ 0, 8.75 ] },
  { id: 'admin', name: 'Admin', color: '#8b5cf6', dock: [ 1.5, 11.75 ], home: [ 0, 11.75 ], console: true },
];

/** The admin's console top, where the MANUAL box waits. */
export const CONSOLE_Y = 0.7;

export const REJECT_BIN: Vec2 = [ 15, 9 ];

/** Set dressing on empty floor, clear of every station, belt and walk path. */
export const PROPS: Prop[] = [
  { kind: 'pallet', position: [ 12.9, 2.3 ], rotation: 0 },
  { kind: 'pallet', position: [ 14.3, 2.3 ], rotation: 0.08 },
  { kind: 'pallet', position: [ 27, 11.9 ], rotation: -0.1 },
  { kind: 'forklift', position: [ 22.6, 1.5 ], rotation: -Math.PI / 2 },
  { kind: 'cone', position: [ 13.8, 10.2 ], rotation: 0 },
  { kind: 'cone', position: [ 16.2, 10.2 ], rotation: 0 },
  { kind: 'cone', position: [ 22.2, 12.6 ], rotation: 0 },
  { kind: 'cable-tray', position: [ 6.25, 1.1 ], rotation: 0 },
  { kind: 'cable-tray', position: [ 31.2, 6.5 ], rotation: Math.PI / 2 },
];

/** Belts between endpoints; via holds the inner corners, so moving a station moves its belt ends. */
export const CONVEYORS: Conveyor[] = [
  { id: 'belt-dock-alice-order-listener', from: 'dock-alice', to: 'order-listener', via: [], kind: 'main' },
  { id: 'belt-order-listener-earning-service', from: 'order-listener', to: 'earning-service', via: [ [ 8.125, 2.75 ], [ 8.125, 5.75 ] ], kind: 'main' },
  { id: 'belt-dock-bob-signup-listener', from: 'dock-bob', to: 'signup-listener', via: [], kind: 'main' },
  { id: 'belt-signup-listener-earning-service', from: 'signup-listener', to: 'earning-service', via: [], kind: 'main' },
  { id: 'belt-dock-cara-review-listener', from: 'dock-cara', to: 'review-listener', via: [], kind: 'main' },
  { id: 'belt-review-listener-earning-service', from: 'review-listener', to: 'earning-service', via: [ [ 8.125, 8.75 ], [ 8.125, 5.75 ] ], kind: 'main' },
  { id: 'belt-earning-service-program-gate', from: 'earning-service', to: 'program-gate', via: [], kind: 'main' },
  { id: 'belt-program-gate-pending-points', from: 'program-gate', to: 'pending-points', via: [ [ 17.25, 5.75 ], [ 17.25, 2.5 ] ], kind: 'main' },
  { id: 'belt-program-gate-ledger-service', from: 'program-gate', to: 'ledger-service', via: [], kind: 'main' },
  { id: 'belt-program-gate-reject-bin', from: 'program-gate', to: 'reject-bin', via: [], kind: 'reject' },
  { id: 'belt-pending-points-ledger-service', from: 'pending-points', to: 'ledger-service', via: [], kind: 'main' },
  { id: 'belt-ledger-service-vault', from: 'ledger-service', to: 'vault', via: [], kind: 'main' },
  { id: 'belt-desk-admin-ledger-service', from: 'dock-admin', to: 'ledger-service', via: [ [ 17.25, 11.75 ], [ 17.25, 5.75 ] ], kind: 'main' },
  { id: 'belt-vault-reversal-listener', from: 'vault', to: 'reversal-listener', via: [ [ 24.25, 11.75 ] ], kind: 'return' },
];

export const BOXES: Box[] = [
  { id: 'alice', event: 'ORDER', points: '120', color: 'earn' },
  { id: 'bob', event: 'SIGNUP', points: '50', color: 'earn' },
  { id: 'cara', event: 'REVIEW', points: '20', color: 'earn' },
  { id: 'admin', event: 'MANUAL', points: '+50', color: 'adjust' },
];

/** Where an endpoint sits on the floor. */
export function endpointPosition( e: Endpoint, stations: Station[] = STATIONS ): Vec2 {
  if ( 'reject-bin' === e ) {
    return REJECT_BIN;
  }
  if ( e.startsWith( 'dock-' ) ) {
    return ACTORS.find( ( a ) => `dock-${ a.id }` === e )!.dock;
  }
  return stations.find( ( s ) => s.id === e )!.position;
}

/** [from position, ...via, to position]. */
export function conveyorPoints( c: Conveyor, stations: Station[] = STATIONS ): Vec2[] {
  return [ endpointPosition( c.from, stations ), ...c.via, endpointPosition( c.to, stations ) ];
}

export const SCENES: Scene[] = [
  { id: 'scene-1', caption: '1 · Alice places an order', show: 'alice', steps: [
    { pkg: 'alice', conveyor: 'belt-dock-alice-order-listener', at: 'order-listener', label: 'woocommerce_new_order → OrderListener::on_stageable()',
      card: { fn: 'OrderListener::on_stageable()', lines: [ 'order #1042 · status pending', 'customer: Alice (user 12) · $120.00' ], out: 'stage the purchase, don\'t award it yet' } },
    { pkg: 'alice', conveyor: 'belt-order-listener-earning-service', at: 'earning-service', label: 'EarningService::preview( PURCHASE ) → PointsEngine::evaluate()',
      card: { fn: 'EarningService::preview( PURCHASE, $ctx )', lines: [ 'PointsEngine::evaluate() scores the order', 'campaign: 1 point per $1 × $120.00' ], out: 'Result: 120 points · writes nothing' } },
    { pkg: 'alice', conveyor: 'belt-earning-service-program-gate', at: 'program-gate', gate: 'lift', label: 'ProgramGate::check() passes',
      card: { fn: 'ProgramGate::check( PURCHASE, $ctx )', lines: [ '✓ program on', '✓ a customer: user 12', '✓ not opted out', '✓ role not excluded' ], out: 'null: no reason, the gate opens' } },
    { pkg: 'alice', conveyor: 'belt-program-gate-pending-points', at: 'pending-points', label: 'PendingPoints::stage() writes _yayloyalty_points_pending: the order is pending',
      card: { fn: 'PendingPoints::stage( 1042, 12, 120 )', lines: [ 'order meta _yayloyalty_points_pending = 120', 'customers.pending_points += 120' ], out: 'no ledger row: waits for completed' } }
  ] },
  { id: 'scene-2', caption: '2 · Bob signs up', show: 'bob', steps: [
    { pkg: 'bob', conveyor: 'belt-dock-bob-signup-listener', at: 'signup-listener', label: 'user_register → SignupListener::on_user_register()',
      card: { fn: 'SignupListener::on_user_register( 13 )', lines: [ 'new account: Bob (user 13)' ], out: 'award the SIGNUP trigger now' } },
    { pkg: 'bob', conveyor: 'belt-signup-listener-earning-service', at: 'earning-service', label: 'EarningService::award( SIGNUP ) → PointsEngine::evaluate()',
      card: { fn: 'EarningService::award( SIGNUP, $ctx )', lines: [ 'preview(): gates, then PointsEngine::evaluate()', 'signup campaign: 50 points' ], out: 'Result: 50 points → LedgerService::earn()' } },
    { pkg: 'bob', conveyor: 'belt-earning-service-program-gate', at: 'program-gate', gate: 'lift', label: 'ProgramGate::check() passes',
      card: { fn: 'ProgramGate::check( SIGNUP, $ctx )', lines: [ '✓ program on', '✓ a customer: user 13', '✓ not opted out', '✓ role not excluded' ], out: 'null: no reason, the gate opens' } },
    { pkg: 'bob', conveyor: 'belt-program-gate-ledger-service', at: 'ledger-service', press: true, label: 'LedgerService::earn() writes EARN · SIGNUP',
      card: { fn: 'LedgerService::earn( $draft )', lines: [ 'one transaction, customer row 13 locked', '✓ idempotency key not used before', 'append EARN · SIGNUP · +50' ], out: 'Outcome: awarded' } },
    { pkg: 'bob', conveyor: 'belt-ledger-service-vault', at: 'vault', settle: [ 23, BELT_Y, 7.1 ], label: 'customers.balance += 50',
      card: { fn: 'customers · user 13', lines: [ 'balance 0 → 50', 'lifetime_earned 0 → 50' ], out: 'committed with the ledger row' } }
  ] },
  { id: 'scene-3', caption: '3 · Alice\'s order is completed', steps: [
    { pulse: 'order-listener', label: 'woocommerce_order_status_changed → completed → OrderListener::on_awardable()',
      card: { fn: 'OrderListener::on_awardable()', lines: [ 'order #1042 · pending → completed', 'each trigger in yayloyalty_order_award_triggers' ], out: 'award( PURCHASE )' } },
    { pulse: 'earning-service', label: 'EarningService::award( PURCHASE ) → ProgramGate → PointsEngine::evaluate()',
      card: { fn: 'EarningService::award( PURCHASE, $ctx )', lines: [ 'evaluates again at the same occurred_at', '✓ ProgramGate passes', 'PointsEngine::evaluate(): 120 points' ], out: 'Result: 120 points → LedgerService::earn()' } },
    { pkg: 'alice', conveyor: 'belt-pending-points-ledger-service', at: 'ledger-service', press: true, label: 'LedgerService::earn() writes EARN · ORDER',
      card: { fn: 'LedgerService::earn( $draft )', lines: [ 'one transaction, customer row 12 locked', '✓ idempotency key not used before', 'append EARN · ORDER · +120' ], out: 'Outcome: awarded' } },
    { pkg: 'alice', conveyor: 'belt-ledger-service-vault', at: 'vault', settle: [ 24.25, BELT_Y, 7.1 ], label: 'customers.balance += 120',
      card: { fn: 'customers · user 12', lines: [ 'balance 0 → 120', 'lifetime_earned 0 → 120' ], out: 'committed with the ledger row' } },
    { pulse: 'pending-points', label: 'PendingPoints::release() marks _yayloyalty_points_released',
      card: { fn: 'PendingPoints::release( 1042 )', lines: [ '_yayloyalty_points_released = now', 'customers.pending_points −= 120', 'mark_awarded(): _yayloyalty_points_earned = 120' ], out: 'inside the same transaction as the earn' } }
  ] },
  { id: 'scene-4', caption: '4 · Cara reviews, but has opted out', show: 'cara', steps: [
    { pkg: 'cara', conveyor: 'belt-dock-cara-review-listener', at: 'review-listener', label: 'comment_post → ReviewListener::on_comment_post()',
      card: { fn: 'ReviewListener::on_comment_post()', lines: [ 'approved product review', 'customer: Cara (user 14)' ], out: 'award the REVIEW trigger' } },
    { pkg: 'cara', conveyor: 'belt-review-listener-earning-service', at: 'earning-service', label: 'EarningService::award( REVIEW )',
      card: { fn: 'EarningService::award( REVIEW, $ctx )', lines: [ 'preview() runs the gates first' ], out: 'ProgramGate::check()' } },
    { pkg: 'cara', conveyor: 'belt-earning-service-program-gate', at: 'program-gate', gate: 'deny', label: 'ProgramGate::check()',
      card: { fn: 'ProgramGate::check( REVIEW, $ctx )', lines: [ '✓ program on', '✓ a customer: user 14', '✗ user meta _yayloyalty_opted_out' ], out: '\'opted_out\': the first failing rule wins' } },
    { pkg: 'cara', conveyor: 'belt-program-gate-reject-bin', at: 'program-gate', tint: 'rejected', label: 'ProgramGate::check() fails: opted out. Nothing is written.',
      card: { fn: 'Outcome::skipped( \'opted_out\' )', lines: [ 'no ledger row', 'no balance change' ], out: 'the package goes to the bin' } }
  ] },
  { id: 'scene-5', caption: '5 · The admin adjusts +50', show: 'admin', steps: [
    { pkg: 'admin', conveyor: 'belt-desk-admin-ledger-service', at: 'ledger-service', press: true, label: 'LedgerService::adjust() writes ADJUST · MANUAL',
      card: { fn: 'LedgerService::adjust( 12, 50, $note, 1 )', lines: [ 'admin (user 1) is the actor', '$adjust_lifetime = true → ADJUST', 'append ADJUST · MANUAL · +50' ], out: 'returns the LedgerEntry' } },
    { pkg: 'admin', conveyor: 'belt-ledger-service-vault', at: 'vault', settle: [ 25.5, BELT_Y, 7.1 ], label: 'customers.balance += 50',
      card: { fn: 'customers · user 12', lines: [ 'balance 120 → 170', 'lifetime_earned 120 → 170' ], out: 'committed with the ledger row' } }
  ] },
  { id: 'scene-6', caption: '6 · Alice\'s order is refunded', steps: [
    { pulse: 'reversal-listener', label: 'woocommerce_order_status_changed → refunded → OrderReversalListener::on_status_refunded()',
      card: { fn: 'OrderReversalListener::on_status_refunded()', lines: [ 'order #1042 · completed → refunded', 'clears any pending figure' ], out: 'Reversal::refunded_status()' } },
    { pulse: 'ledger-service', press: true, label: 'Reversal::refunded_status() → LedgerService::refund_earn() writes REFUND_EARN',
      card: { fn: 'LedgerService::refund_earn( $earn, … )', lines: [ 'finds order #1042\'s EARN row', '✓ idempotency key not used before', 'append REFUND_EARN · −120' ], out: 'a keyed row: a replay writes nothing' } },
    { pkg: 'alice', conveyor: 'belt-vault-reversal-listener', at: 'reversal-listener', tint: 'refund', label: 'customers.balance −= 120: the points leave the vault',
      card: { fn: 'customers · user 12', lines: [ 'balance 170 → 50', 'lifetime_earned 170 → 50' ], out: 'the 120 points leave the vault' } }
  ] }
];

