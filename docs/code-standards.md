# YayLoyalty — Code Standards

Enforced by `phpcs.xml.dist` where a sniff exists; enforced by review where one does not. Rules marked **[REVIEW]** have no automated check — they are the ones most worth catching in a pull request.

Companion: [`data-model.md`](./data-model.md).

---

## 1. File naming

The one genuine conflict in this repo, resolved explicitly.

The autoloader in `yayloyalty.php` maps `YayLoyalty\Models\Customer` → `includes/Models/Customer.php`. **PHP class filenames must therefore be PascalCase**, matching the class name exactly. `WordPress.Files.FileName` (which wants `class-customer.php`) is excluded from the ruleset for this reason.

| Kind | Case | Example |
|---|---|---|
| PHP class file | PascalCase — **mandatory**, autoloader depends on it | `Models/LedgerEntry.php` |
| React component | PascalCase | `DashboardPage.tsx` |
| TS module | kebab + dot-namespace | `settings.api.ts`, `customer.queries.ts` |
| Docs, plans | kebab-case | `code-standards.md` |

## 2. PHP style

- 4-space indent. No tabs.
- Aligned `=>` in multi-line array literals.
- Spaces inside parentheses: `foo( $bar )`.
- `defined( 'ABSPATH' ) || exit;` at the top of **every** PHP file.
- Return types on all public methods. PHP 7.4 supports them; use them.
- Docblocks on public methods where the signature is not self-explanatory.

## 3. Casing

| Element | Case |
|---|---|
| Methods, properties, variables | `snake_case` |
| Classes, interfaces, traits | PascalCase |
| Constants | `UPPER_SNAKE` |
| Hooks | `yayloyalty_` prefix |

Filters carry data. Actions cause side effects. Do not use a filter for its side effects.

## 4. Dates — always UTC

**Never use `current_time()` for anything that gets stored.** Always `Dates::now()`, which returns `gmdate( 'Y-m-d H:i:s' )`.

Every `DATETIME` in this plugin is UTC. Conversion to the site timezone happens only at the edges: admin form rendering, email templates, REST responses intended for display. A timestamp that enters the database in site time is a bug that surfaces months later as an off-by-hours expiry.

## 5. Money — bind as `%s`, never `%f`

Every `DECIMAL(19,4)` column is carried as a **string** end to end, and bound with `%s`.

Binding a decimal through `%f` converts it to a float, and floats cannot represent most decimal fractions exactly. The affected columns are precisely the ones where a rounding error is a financial defect: `rate_snapshot`, `base_value`, `value`, `lifetime_spend`, `threshold`, `tier_progress_value`, `basis_value`.

**[REVIEW]** In TypeScript, money is `z.string()`. Never `parseFloat` a money field; typing it as a string makes TS reject arithmetic on it.

## 6. Database access

**`$wpdb` appears only in `Repositories/` and `Database/`.** Nowhere else — not in controllers, services, models, helpers or engine classes.

- One repository per table. A repository never touches a second table.
- A repository **never opens a transaction**. Atomicity is a service concern.
- All SQL prepared. `search` terms go through `$wpdb->esc_like()` before `prepare()`.
- `orderby` is whitelisted per repository. Never interpolate a caller-supplied column name.
- **Never memoize `$wpdb->prefix` or a table name.** Multisite activation switches blogs mid-request; a cached prefix installs every blog's tables into the wrong one.

### Write methods throw

`$wpdb->insert()` returns `false` on failure — it does not throw. Because rollback in this codebase is exception-driven, a repository that quietly returns `false` will let a failed write commit.

Every repository write checks the return value and `$wpdb->last_error`, then throws `QueryException`.

### Index DDL grammar

Write indexes as `UNIQUE KEY name (cols)` or `KEY name (cols)`. Never bare `UNIQUE (cols)`, never `INDEX`.

`dbDelta` only recognises the `KEY` forms. A bare `UNIQUE (...)` succeeds on first install and then silently fails to normalise on every upgrade — the index quietly disappears on the exact code path nobody tests. Also: name every key, put two spaces after `PRIMARY KEY`, and state `ENGINE=InnoDB` explicitly (savepoints do not exist on MyISAM).

## 7. Transactions

**Only `Transaction::run()` opens or nests a transaction.** Raw `START TRANSACTION` / `COMMIT` / `ROLLBACK` are banned outside `Database/Transaction.php`. **[REVIEW]** — no sniff for this; catch it in review.

The connection is **not ours**. WooCommerce issues `START TRANSACTION` on the same `global $wpdb` (`wc_transaction_query`), and so do sibling Yay plugins installed alongside this one. Consequences:

- At depth 0 we may already be inside someone else's transaction. Issuing `START TRANSACTION` there **implicitly commits their in-flight work** — a loyalty plugin corrupting order data on the host's rollback path.
- `$wpdb` silently reconnects on error 2006 and resumes in autocommit, so a depth counter can outlive the transaction it counts.
- A deadlock (error 1213) rolls back everything and destroys every savepoint.

Therefore `Transaction::run()` probes for a foreign transaction and takes a savepoint rather than starting one, guards connection identity before every commit/rollback, and treats a failed `ROLLBACK TO SAVEPOINT` as fatal rather than continuing.

**Lock order is always `customers` before `ledger`.** Never the reverse — interleaved operations on two users deadlock otherwise.

## 8. Layers

| Layer | Owns | Must not |
|---|---|---|
| `Models/` | Typed data, casts, REST shape | Touch the database |
| `Repositories/` | All SQL for exactly one table | Open transactions; touch a second table |
| `Services/` | Invariants, transactions, cross-table writes | Contain SQL |
| `Controllers/` | HTTP shape, schema, permissions | Contain SQL or business invariants |

**Definition tables get no service.** A service that only forwards CRUD to one repository is ceremony. Add one when an operation must touch a second table atomically — that is when it earns its existence.

### Instantiation: services and repositories are plain objects

`Utils/SingletonTrait` is for bootstrap classes only (`RestAPI`, `Settings`, `RegisterFacade`). **Models, Repositories and Services never use it**, for two reasons:

- `get_instance( ...$args )` silently ignores `$args` after the first call, so constructor injection and the trait are incompatible — the second caller silently gets the first caller's dependencies.
- `get_instance()` is `public static`, so it hands global access to anything that wants it.

**[REVIEW]** `LedgerService` is the only class that receives both `LedgerRepository` and `CustomerRepository`, and it is constructed in exactly one place. PHP has no package-private, so this is a convention plus a single construction site plus this review rule — **not** something the language enforces. Do not describe it as "structural".

## 9. REST

- Namespace `yayloyalty/v1`. Routes registered in `register_routes()`, never in a constructor.
- Every resource declares `get_item_schema()`, with `context` (`view`/`edit`) and `readonly` on every property.
- **`readonly` properties are stripped from write args, not merely ignored.** Build write args by filtering the schema. Passing the schema whole makes every endpoint mass-assignable: a `PUT` carrying `is_builtin` or `actor_id` would otherwise defeat the guards that depend on them.
- Server-owned and always readonly: `id`, `created_at`, `updated_at`, `is_builtin`, `balance_after`, `actor_id`, and every aggregate on `customers`.
- `actor_id` is always `get_current_user_id()`. Never read it from a request body.
- ID route params are `(?P<id>[\d]+)` with a `validate_callback` confirming the record exists.
- Payload fields are `snake_case`. The pagination envelope is camelCase (`currentPage`, `totalPage`, `totalItems`, `perPage`, `data`), matching `PaginatedResponse<T>` in the admin app — one envelope, not two.
- Error responses never echo `$exception->getMessage()`; log the detail, return a generic message with a correlation id.

## 10. PHP 7.4 pseudo-enums

No native enums. Closed vocabularies are `final class` + `const` + `all()` + `is_valid()` + `assert()`.

**`assert()` runs at the service boundary, not in the repository.** With no enum type there is no compile-time check, so if the service does not assert, an invalid value surfaces only as a DB error — far from the caller, with a message that names a column instead of the mistake.

Fact-table entities are immutable **by convention**: private properties, getters only, no setters, `with_*()` returns a clone. `readonly` does not exist in 7.4, so the convention is the enforcement. Say so in each such class's docblock.

## 11. Status and liveness

`status` stores merchant **intent** only: `active`, `paused`, `draft`. Never `scheduled` or `ended`.

Whether something is live *right now* is computed at read time from `status` plus `starts_at`/`ends_at`, evaluated against the **event's** timestamp — an order's award time, not the current request time. A late-processed order must be scored under the rules that were live when it happened.

**No cron job may flip a status.** If you find yourself writing one, the model is being worked around.

## 12. JSON columns

Stored as `LONGTEXT`, validated in PHP. Every blob carries a `schema_version` key so formats can evolve without a table migration.

Decoding is **total**: malformed stored JSON returns the default and logs. It never throws. A corrupt `config` blob should degrade one campaign, not white-screen the admin.

## 13. Running the linter

```bash
composer lint      # phpcs
composer lint:fix  # phpcbf
```

Every exclusion in `phpcs.xml.dist` carries a one-line reason. **An exclusion without a reason is a bug** — it means a sniff was disabled to make the build green rather than because it was wrong.

### Known blind spot: the linter cannot see PHP 8 syntax **[REVIEW]**

`phpcs.xml.dist` sets `testVersion` to `7.4-` and this is wired correctly — PHPCompatibility does flag constructs newer than the floor *that it knows about*. But `phpcompatibility/php-compatibility` is pinned at 9.3.5, the last stable release, which **predates PHP 8.0 and ships no PHP 8 sniffs**.

Verified 2026-09-09: a file using the nullsafe operator `?->` passes `composer lint` clean.

So the following will **not** be caught by tooling, and are the exact features the PHP 7.4 floor exists to prevent:

- `enum` declarations
- `match` expressions
- constructor property promotion
- `readonly` properties
- nullsafe operator `?->`
- named arguments
- union types

**These are a review responsibility.** Local development runs PHP 8.x, so this code will run fine on the developer's machine and fatal on a 7.4 host. When reviewing, read for these constructs deliberately — the linter passing is not evidence of their absence.

Closing this gap later means either pinning `phpcompatibility/php-compatibility` to `dev-develop` (which has PHP 8.x sniffs) or adding PHPStan with `phpVersion: 70400`. Deliberately deferred; revisit when CI is set up.
