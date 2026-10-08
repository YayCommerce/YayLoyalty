# Campaign Priority Ties Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is the source.

**Status:** done 2026-10-08
**Goal:** Stop new priority ties between campaigns of the same trigger: REST writes can no longer set `priority`, and a restored campaign goes to the top of its group.
**Spec:** none, planned from the request
**Architecture:** Priority gets exactly two writers: create, which puts a campaign on top of its group through `CampaignRepository::top_priority()`, and the drag-to-reorder route, which renumbers a whole group. This plan marks `priority` read-only in the REST schema, removes the two places that copy it from a request, and adds a third placement rule: archived → non-archived goes to top, because an archived row keeps its old number while reorders skip it. We chose this over shifting other campaigns to make room, because the drag already does that job.
**Touches:** `includes/Controllers/CampaignRestController.php`, `apps/admin/src/pro/campaigns/editor/CampaignEditorConditions.tsx`
**Tests:** `tests/integration/Controllers/CampaignRouteTest.php` (new, integration suite, `composer test:integration`): guards that a `priority` in a POST or PUT body is ignored, that restoring an archived campaign gives it `top_priority()` of its own trigger, and that a non-archived status change keeps its priority.

## Requirements

- **Goal:** Neither the admin UI nor the REST API can create two non-archived campaigns of one trigger with the same priority. The one exception is the concurrent create race listed in Review focus.
- **Out of scope:** repairing ties that already exist (no upgrade routine), the engine's tie-break (`priority DESC, id DESC` stays), the table's tie badge (`CampaignTable.tsx:808` stays for old ties), `CampaignRepository::reorder()`.
- **Acceptance criteria:**
  1. `POST /yayloyalty/v1/campaigns` with `"priority": 99` creates the campaign at `top_priority( $trigger )`. The `99` is ignored without an error.
  2. `PUT /yayloyalty/v1/campaigns/{id}` with `"priority": 99` leaves the stored priority unchanged and still applies the request's other fields.
  3. A PUT that moves a campaign from `archived` to `active`, `paused` or `draft` sets its priority to `top_priority()` of its trigger, computed while the campaign is still archived and so outside the group.
  4. A PUT that changes status between non-archived values (for example `paused` → `active`) keeps the priority.
  5. GET responses still return `priority`.
  6. The conditions hint in the campaign editor no longer tells merchants to use the same priority for OR.
  7. End to end in wp-admin (Pro build): a purchase group holds the built-in and campaigns A and B. Archive A, drag the group so that A's old number now belongs to another row, then restore A with `PUT campaigns/{id}` `{"status":"active"}`: the table has no Restore action for an archived row. A is the top row of the purchase group, and no row in that group shows the "Shares this priority" badge.
- **Decided with the user:**
  - REST `priority` on create/update: ignored on writes; only `campaigns/reorder` writes it.
  - A restored campaign goes to the top of its group.
  - Existing ties are not repaired.
  - Test: add the integration test above.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly` properties, promoted constructor properties, `?->`, named arguments or union types. The `'readonly' => true` key in a REST schema array is fine.
- Lite names Pro in exactly one place. This change touches only Lite PHP and the Pro SPA folder, and adds no new Pro reference.
- Never hand-edit `vendor/`, `vendor-prefixed/`, `node_modules/`, `assets/dist/` or `release/`.
- Branch from `dev/edd` as `<name>/fix/campaign-priority-ties`. Commit only when asked. Stage files by name. Use Conventional Commits with the `Co-Authored-By` trailer from CLAUDE.md.
- `composer test:integration` exiting 0 doesn't prove it ran. Read the output for the test count (see `docs/known-issues.md`).

## Review focus

- **A PUT that archives and restores in one request can't happen.** The status goes archived → X only when the stored status is archived, so the check must read `is_archived()` before `apply_update()` runs. Task 1's restore test covers this.
- **`top_priority()` must not see the campaign being restored.** It reads `all_non_archived()`, and the row is still archived in the database when the call happens. Compute it before `campaigns->update()`. Task 1's restore test expects 4 when the group's top is 3 and the restored row was 1.
- **Other triggers must not count.** A signup campaign at priority 10 must not lift a restored purchase campaign to 11. Task 1's restore test inserts one.
- **Concurrent creates can still tie.** Two POSTs for the same trigger at the same moment can both read the same `top_priority()`. This is accepted, not fixed: the tie badge shows it and a drag clears it. No step covers it.
- **An external caller that sent `priority` now gets no error.** WordPress REST ignores unknown body keys once the arg isn't declared. This is intended (decided above), and acceptance criterion 2 checks it.

---

### Task 1: Priority is read-only on REST writes, and a restored campaign goes to the top

**Files:**
- Modify: `includes/Controllers/CampaignRestController.php:220-222` (create), `:240-268` (`update_item`), `:310-314` (`apply_update`), `:427-430` (schema)
- Test: `tests/integration/Controllers/CampaignRouteTest.php` (new)

**Interfaces:**
- Consumes: `CampaignRepository::top_priority( string $trigger ): int`, `Campaign::is_archived(): bool`, `Campaign::set_priority( int $priority ): void`, all existing.
- Produces: nothing new. The REST contract changes: `priority` drops out of `get_write_args()` for POST and PUT.

- [ ] **Step 1: Write the failing tests**

Model the class on `tests/integration/Controllers/RewardRouteTest.php`:
- `extends IntegrationTestCase`, and `setUp()` calls `rest_get_server()`.
- Copy its private helpers `act_as_admin()` and `send()` as they are.
- Add `insert_campaign( string $name, string $trigger, string $status, int $priority ): int`. It inserts through `( new CampaignRepository() )->insert( new Campaign( null, $name, sanitize_title( $name ), $trigger, $status, $priority, Campaign::default_config( $trigger ), [], null, null, false, null, $now, $now ) )`.
- Add `stored_priority( int $id ): int`, which reads through `find_campaign()` after `flush_cache()`.

```php
public function test_create_ignores_a_priority_in_the_body(): void {
    $this->act_as_admin();
    $this->insert_campaign( 'Top now', CampaignTrigger::PURCHASE, Status::ACTIVE, 4 );

    $response = $this->send( 'POST', '/yayloyalty/v1/campaigns', [
        'name'     => 'New',
        'trigger'  => CampaignTrigger::PURCHASE,
        'priority' => 99,
        'config'   => [ 'points_per_unit' => 1 ],
    ] );

    $this->assertSame( 200, $response->get_status() ); // create_item returns a plain array, so 200, not 201.
    $this->assertSame( 5, $response->get_data()['priority'] );
}

public function test_update_ignores_a_priority_in_the_body(): void {
    $this->act_as_admin();
    $id = $this->insert_campaign( 'Kept', CampaignTrigger::PURCHASE, Status::ACTIVE, 2 );

    $response = $this->send( 'PUT', "/yayloyalty/v1/campaigns/{$id}", [ 'name' => 'Renamed', 'priority' => 99 ] );

    $this->assertSame( 200, $response->get_status() );
    $this->assertSame( 'Renamed', $response->get_data()['name'] );
    $this->assertSame( 2, $this->stored_priority( $id ) );
}

public function test_restoring_an_archived_campaign_puts_it_on_top_of_its_group(): void {
    $this->act_as_admin();
    $restored = $this->insert_campaign( 'Restored', CampaignTrigger::PURCHASE, Status::ARCHIVED, 1 );
    $this->insert_campaign( 'Tied before', CampaignTrigger::PURCHASE, Status::ACTIVE, 1 );
    $this->insert_campaign( 'Group top', CampaignTrigger::PURCHASE, Status::ACTIVE, 3 );
    $this->insert_campaign( 'Other trigger', CampaignTrigger::SIGNUP, Status::ACTIVE, 10 );

    $response = $this->send( 'PUT', "/yayloyalty/v1/campaigns/{$restored}", [ 'status' => Status::ACTIVE ] );

    $this->assertSame( 200, $response->get_status() );
    $this->assertSame( 4, $response->get_data()['priority'] );
    $this->assertSame( 4, $this->stored_priority( $restored ) );
}

public function test_a_status_change_outside_archived_keeps_the_priority(): void {
    $this->act_as_admin();
    $paused = $this->insert_campaign( 'Paused', CampaignTrigger::PURCHASE, Status::PAUSED, 1 );
    $this->insert_campaign( 'Group top', CampaignTrigger::PURCHASE, Status::ACTIVE, 3 );

    $this->send( 'PUT', "/yayloyalty/v1/campaigns/{$paused}", [ 'status' => Status::ACTIVE ] );

    $this->assertSame( 1, $this->stored_priority( $paused ) );
}
```

- [ ] **Step 2: Run the tests to confirm the right ones fail**

Run: `composer test:integration -- --filter CampaignRouteTest` with `YAYLOYALTY_TEST_SOCKET` exported.
Expected:
- `test_create_ignores_a_priority_in_the_body` fails (99 ≠ 5).
- `test_update_ignores_a_priority_in_the_body` fails (99 ≠ 2).
- `test_restoring_…` fails (1 ≠ 4).
- `test_a_status_change_outside_archived_keeps_the_priority` passes.
- The output shows 4 tests ran.

- [ ] **Step 3: Make `priority` read-only in `define_item_schema()`**

Add `'readonly' => true` to the `priority` property, plus `'description' => __( 'Highest wins within a trigger. Set on create and by campaigns/reorder; ignored on create and update.', 'yayloyalty' )`. `get_write_args()` then drops it.

- [ ] **Step 4: Stop copying `priority` from the request**

- In `create_item()`, the constructor argument becomes `$this->campaigns->top_priority( $trigger )`. Keep the comment above it, reworded to "A new campaign goes on top of its group, where a drag in the campaigns table can move it."
- In `apply_update()`, delete the `$request['priority']` block.

Both reads are needed. `$request['priority']` still returns a body value when the arg isn't declared.

- [ ] **Step 5: Restore to top in `update_item()`**

- Read `$was_archived = $campaign->is_archived();` before `self::apply_update( $campaign, $request );`.
- After `apply_update()` and before `to_update_row()`: when `$was_archived && ! $campaign->is_archived()`, call `$campaign->set_priority( $this->campaigns->top_priority( $campaign->get_trigger() ) )`.
- Add a one-line comment saying why: an archived row keeps its old number while reorders skip it, so restoring it in place could tie.

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter CampaignRouteTest`
Expected: 4 tests, 4 passing.

Then run `composer test:integration`.
Expected: no new failures compared with `dev/edd`.

- [ ] **Step 7: Lint the changed files**

Run: `vendor/bin/phpcs includes/Controllers/CampaignRestController.php tests/integration/Controllers/CampaignRouteTest.php`
Expected: no errors on the lines this task changed. `composer lint` fails today (`docs/known-issues.md`), so compare against `dev/edd` for anything outside them.

- [ ] **Step 8: Commit**

Stage: `includes/Controllers/CampaignRestController.php`, `tests/integration/Controllers/CampaignRouteTest.php`
Message: `fix(campaigns): stop REST writes and restores from tying campaign priority`

### Task 2: Editor hint no longer recommends equal priorities

**Files:**
- Modify: `apps/admin/src/pro/campaigns/editor/CampaignEditorConditions.tsx:90-93`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing.

- [ ] **Step 1: Replace the hint string**

The new text is `'Conditions are combined with AND.'`. The text domain stays `'yayloyalty'`. The OR advice is dropped: the editor no longer suggests a workaround for OR.

- [ ] **Step 2: Verify**

Run: `git grep -n "same priority" -- apps/admin/src`
Expected: no output.

Run: `pnpm --filter @yayloyalty/admin-page test`
Expected: same result as on `dev/edd`.

- [ ] **Step 3: End-to-end check in wp-admin (acceptance criterion 7)**

Start: `./scripts/run.sh dev pro`. Afterwards, check `git status` for rewritten `composer.json` or `scoper.inc.php` and don't stage them.

Open: Campaigns page.

Action:
1. Create purchase campaigns A and B.
2. Archive A.
3. Drag B to the top of the purchase group.
4. Restore A with `PUT /yayloyalty/v1/campaigns/{id}` and body `{"status":"active"}` (the archived row menu offers only View performance and Duplicate), then reload.
5. Back on the campaigns table, look at the purchase group.

Expected: A is the top row, and no row in the purchase group shows the "Shares this priority with another campaign in this group" badge. Open A's editor: the conditions hint shows the new text.

- [ ] **Step 4: Commit**

Stage: `apps/admin/src/pro/campaigns/editor/CampaignEditorConditions.tsx`
Message: `fix(campaigns): drop the equal-priority OR advice from the conditions hint`
