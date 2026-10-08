---
paths:
  - "includes/**/*.php"
  - "yayloyalty.php"
  - "YayLoyaltyPluginAdapter.php"
  - "tests/**/*.php"
  - "scripts/*.php"
---

<!--
Purpose: rules for writing the plugin's PHP: the house style, the parts of the PHP 7.4 floor that no tool checks, and the patterns new code follows. Loads when Claude reads a matching file.
Update when: phpcs.xml.dist changes, the PHP or WordPress floor moves, or one of these patterns changes.
-->

# PHP

Layers, writers and boundaries are in [docs/architecture.md § Server layers and write ownership](../../docs/architecture.md#server-layers-and-write-ownership), and the test harness in [§ Tests](../../docs/architecture.md#tests). Money, dates, file names and comments are in [docs/conventions.md](../../docs/conventions.md). Hard rules 2, 4 and 5 in `CLAUDE.md` cover Pro, PHP 7.4 and the ledger.

## Style

- `phpcs.xml.dist` applies WordPress-Extra, with this repo's exceptions: 4-space indents, short arrays `[]` and PascalCase class files. The rest is WordPress style: Yoda conditions, spaces inside parentheses, aligned `=>` in multi-line arrays, the `yayloyalty` text domain, and the `yayloyalty_` prefix on globals and hooks.
- A class file in `includes/` opens with its namespace, its `use` lines, `defined( 'ABSPATH' ) || exit;` and the class docblock. Product files don't declare `strict_types`.
- Lint only what you changed with `composer lint -- <files>`. A run over the whole repo fails today ([docs/known-issues.md](../../docs/known-issues.md)).

## PHP 7.4, beyond hard rule 4

- These are out too, and neither phpcs nor `php -l` on PHP 8 reports them: `mixed`, `static`, `never` and intersection types; a `catch` without a variable; `throw` as an expression; a trailing comma after the last parameter of a declaration; `new` in a default value; first-class callables such as `strlen(...)`; `$object::class`; and an attribute on the same line as code.
- Use a PHP 8 function only where WordPress 6.5 polyfills it: `str_contains`, `str_starts_with`, `str_ends_with` and `array_is_list`. Later polyfills, such as `array_find`, need a newer WordPress.

## Patterns for new code

- **Logging** goes only through `Log::error()`, `warning()` or `debug()` in `includes/Utils/Log.php`, with the reporting class as the context. Nothing else calls `wc_get_logger()` or `error_log()`.
- **SQL:** values go through `$wpdb->prepare()` placeholders, and table and column names go in backticks through `esc_sql()`. `ORDER BY` comes only from `sortable_columns()`, and IN lists from `Sql::in_placeholders()`. A write checks `false === $result` and throws `QueryException`.
- **REST:** a route's callback is `fn( $request ) => $this->exec_read( … )` or `exec_write( … )`. Validate before writing, and answer with a `$this->error_*()` response. A thrown exception becomes a 500, and a rollback doesn't undo the object cache. `includes/Controllers/LedgerRestController.php` is the model.
- **Hooks:** every hook the plugin fires has a docblock. An action whose subscribers may write caches or call out fires from `Transaction::after_commit()`, because those writes can't be rolled back. New code attaches its hooks in a `register()` method that a composition root calls once. `SingletonTrait` is only for the boot classes.
- **Settings and design keys** go in both the defaults and the `schema()`, `SettingsHelper`'s for settings and `DesignSchema`'s for design, because a key missing from `schema()` is dropped on read and on write. Then add the key to the matching zod schema in `apps/admin/src/lib/schema/`.
- **Storage:** JSON columns go through `includes/Utils/Json.php`. A table schema follows the dbDelta rules in the docblock of `includes/Database/Schema/AbstractTableSchema.php`.
- **Email templates** load through WooCommerce's template lookup, so a theme can override them. Their file names and the variables they receive are a public contract.
