# Eval cases — yay:code-corrector

Run one at a time from the plugin root with a clean tree (`git status --short` empty):

1. Save the case diff to a temp file, `git apply --check` it, then `git apply` it.
2. On `dev/edd`, invoke `/yay:code-corrector` with no argument (the branch adds nothing there, so the scope is just the applied patch).
3. Compare the report with **Expected**. Must name the owner and the patched `file:line`; must not edit anything.
4. `git apply -R` the diff. If it fails, the skill edited without approval → case fails.

A case whose `--check` fails has rotted: fix the diff or drop the case.

## literal-option-name

**Expected:** includes/Controllers/DashboardRestController.php → `SettingsHelper::OPTION_SETTINGS` (literal option name)  
**Owner evidence:** grep: `includes/Helpers/SettingsHelper.php:13`

```diff
diff --git a/includes/Controllers/DashboardRestController.php b/includes/Controllers/DashboardRestController.php
index f22050c..f782309 100644
--- a/includes/Controllers/DashboardRestController.php
+++ b/includes/Controllers/DashboardRestController.php
@@ -117,6 +117,10 @@ final class DashboardRestController extends AbstractRestController {
     public function get_overview( \WP_REST_Request $request ): array {
         $range = (int) $request['range'];
 
+        if ( empty( get_option( 'yayloyalty_settings', [] ) ) ) {
+            return [];
+        }
+
         if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $request['scenario'] ) ) {
             return DashboardFixture::scenario( (string) $request['scenario'], $range );
         }
```

## settings-default-copy

**Expected:** includes/Controllers/CustomerAdjustPreviewRestController.php → `SettingsHelper::get_default_settings()` (settings default copy)  
**Owner evidence:** grep: `includes/Helpers/SettingsHelper.php:41`

```diff
diff --git a/includes/Controllers/CustomerAdjustPreviewRestController.php b/includes/Controllers/CustomerAdjustPreviewRestController.php
index 2fb8e2a..8d50133 100644
--- a/includes/Controllers/CustomerAdjustPreviewRestController.php
+++ b/includes/Controllers/CustomerAdjustPreviewRestController.php
@@ -3,6 +3,7 @@ namespace YayLoyalty\Controllers;
 
 use YayLoyalty\Access\Capabilities;
 use YayLoyalty\Enums\CustomerFilters;
+use YayLoyalty\Helpers\SettingsHelper;
 use YayLoyalty\Repositories\CustomerRepository;
 use YayLoyalty\Repositories\DashboardRepository;
 use YayLoyalty\Repositories\RewardRepository;
@@ -82,6 +83,7 @@ final class CustomerAdjustPreviewRestController extends AbstractRestController {
             'count'                    => is_array( $filters ) ? $this->count_matching( $filters ) : null,
             'liability_points'         => $this->dashboard->liability_points(),
             'points_per_currency_unit' => $this->rewards->points_per_currency_unit(),
+            'adjustment_ceiling'       => (int) ( SettingsHelper::get_settings()['advanced']['adjustment_ceiling'] ?? 0 ),
         ];
     }
 
```

## points-math-copy

**Expected:** includes/Engine/Rewards/Clamp.php → `PointsMath::points_to_currency()` (points arithmetic reimplemented)  
**Owner evidence:** grep: `includes/Utils/PointsMath.php:75`

```diff
diff --git a/includes/Engine/Rewards/Clamp.php b/includes/Engine/Rewards/Clamp.php
index 6d83086..f104734 100644
--- a/includes/Engine/Rewards/Clamp.php
+++ b/includes/Engine/Rewards/Clamp.php
@@ -101,7 +101,7 @@ final class Clamp {
             }
         }
 
-        $value = PointsMath::points_to_currency( $applied, $rate, $decimals ) ?? 0.0;
+        $value = $rate > 0 ? round( $applied / $rate, $decimals ) : 0.0;
 
         return Result::allowed( $reward, max( 0, $requested ), $applied, $value, $currency, $rate, $bound_by );
     }
```

## ts-design-bound-copy

**Expected:** apps/admin/src/pages/settings/DesignLauncherSection.tsx → `DesignSchema` corner-radius bound, mirrored in `design.schema.ts` (bound literal)  
**Owner evidence:** grep: `includes/Helpers/DesignSchema.php:102`, `apps/admin/src/lib/schema/design.schema.ts:43`

```diff
diff --git a/apps/admin/src/pages/settings/DesignLauncherSection.tsx b/apps/admin/src/pages/settings/DesignLauncherSection.tsx
index 07f4840..dc3020d 100644
--- a/apps/admin/src/pages/settings/DesignLauncherSection.tsx
+++ b/apps/admin/src/pages/settings/DesignLauncherSection.tsx
@@ -101,7 +101,7 @@ export function DesignLauncherSection() {
                   min={0}
                   max={24}
                   value={field.value}
-                  onValueChange={field.onChange}
+                  onValueChange={(value) => field.onChange(Math.min(24, Math.max(0, value ?? 0)))}
                 >
                   <NumberInputInput id="design-launcher-corner-radius" />
                   <NumberInputUnit unit="px" />
```

## helper-reimplemented

**Expected:** apps/admin/src/pages/customers/drawer/CustomerDrawerExpiryStats.tsx → `formatPointsAsMoney` (`lib/helpers/points.helper.ts`) (helper reimplemented)  
**Owner evidence:** grep: `apps/admin/src/lib/helpers/points.helper.ts:51`

```diff
diff --git a/apps/admin/src/pages/customers/drawer/CustomerDrawerExpiryStats.tsx b/apps/admin/src/pages/customers/drawer/CustomerDrawerExpiryStats.tsx
index 11a7329..e6e598a 100644
--- a/apps/admin/src/pages/customers/drawer/CustomerDrawerExpiryStats.tsx
+++ b/apps/admin/src/pages/customers/drawer/CustomerDrawerExpiryStats.tsx
@@ -1,4 +1,5 @@
 import { utcToDate } from '@/lib/helpers/format.helper';
+import { parseWPCurrency } from '@/lib/helpers/format.helper';
 import { formatPoints, formatPointsAsMoney } from '@/lib/helpers/points.helper';
 import type { Customer, DrawerExpiry } from '@/lib/schema/customer.schema';
 import { CustomerDrawerStat } from './CustomerDrawerStat';
@@ -22,7 +23,7 @@ export function CustomerDrawerExpiryStats({ customer, expiry }: { customer: Cust
         label={STAT_BALANCE()}
         size="lg"
         value={formatPoints(customer.balance)}
-        subLine={formatPointsAsMoney(customer.balance, rate) ?? undefined}
+        subLine={rate > 0 ? parseWPCurrency(Math.round((customer.balance / rate) * 100) / 100) : undefined}
       />
       <CustomerDrawerStat
         label={expiringInDaysLabel(daysRemaining)}
```

## comment-restates-rule

**Expected:** includes/Controllers/LedgerRestController.php → `Enums\LedgerType` (comment restates rule)  
**Owner evidence:** grep: `includes/Enums/LedgerType.php:33`

```diff
diff --git a/includes/Controllers/LedgerRestController.php b/includes/Controllers/LedgerRestController.php
index 00e0f05..6f98736 100644
--- a/includes/Controllers/LedgerRestController.php
+++ b/includes/Controllers/LedgerRestController.php
@@ -94,6 +94,7 @@ final class LedgerRestController extends AbstractRestController {
                 ],
                 'type'           => [
                     'type'     => 'string',
+                    // One of earn, redeem, refund_earn, refund_redeem, adjust or expire.
                     'enum'     => LedgerType::all(),
                     'context'  => [ 'view' ],
                     'readonly' => true,
```

## allowed-mirror-in-sync

**Expected:** none (report says "No duplicates.")  
**Owner evidence:** mirror pair: `SettingsHelper` (defaults + `schema()`) ↔ `settings.schema.ts`

```diff
diff --git a/apps/admin/src/lib/schema/settings.schema.ts b/apps/admin/src/lib/schema/settings.schema.ts
index 9db9678..240377b 100644
--- a/apps/admin/src/lib/schema/settings.schema.ts
+++ b/apps/admin/src/lib/schema/settings.schema.ts
@@ -10,6 +10,7 @@ export const settingsSchema = z.object({
   advanced: z.object({
     /** Largest manual adjustment a non-administrator may make; 0 = unlimited. */
     adjustment_ceiling: z.number().int().nonnegative(),
+    log_retention_days: z.number().int().nonnegative(),
   }),
   /** Stamped by SettingsHelper on save; a blob written before versioning reads as 0. */
   schema_version: z.number().int().optional(),
diff --git a/includes/Helpers/SettingsHelper.php b/includes/Helpers/SettingsHelper.php
index 60c5143..74c75ab 100644
--- a/includes/Helpers/SettingsHelper.php
+++ b/includes/Helpers/SettingsHelper.php
@@ -39,6 +39,7 @@ class SettingsHelper {
             'advanced' => [
                 // 0 = unlimited. Read via Capabilities::ceiling().
                 'adjustment_ceiling' => 0,
+                'log_retention_days' => 90,
             ],
         ];
     }
@@ -58,6 +59,7 @@ class SettingsHelper {
             ],
             'advanced' => [
                 'adjustment_ceiling' => 'int',
+                'log_retention_days' => 'int',
             ],
         ];
     }
```

## clean

**Expected:** none (report says "No duplicates.")  
**Owner evidence:** UI copy only

```diff
diff --git a/apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts b/apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts
index 695df2e..28e2784 100644
--- a/apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts
+++ b/apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts
@@ -30,7 +30,7 @@ export const TOP_TIER_REACHED_LABEL = () => __('Top tier reached', 'yayloyalty')
 // ─── Stats (1e/1f/3c) ────────────────────────────────
 
 export const STAT_BALANCE = () => __('Balance', 'yayloyalty');
-export const STAT_PENDING = () => __('Pending', 'yayloyalty');
+export const STAT_PENDING = () => __('Pending points', 'yayloyalty');
 export const STAT_EXPIRES = () => __('Expires', 'yayloyalty');
 export const STAT_LIFETIME_EARNED = () => __('Lifetime earned', 'yayloyalty');
 export const STAT_LIFETIME_REDEEMED = () => __('Lifetime redeemed', 'yayloyalty');
```
