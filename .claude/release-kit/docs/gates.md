# QA gates

<!-- Purpose: what each gate in lib/php/gates.php checks and how an edition configures it.
Update when: a gate is added, removed or changes what it checks. -->

Every build (test, release, support) runs all gates on the final zip. Any `fail` stops the build:
nothing is written to `dist/<plugin>/<edition>/`, and the zip, `GATES.json` and the worktree are kept
for inspection. `warn` is reported but does not stop the build.

| gate | fails when | configure |
|---|---|---|
| `structure` | the zip has anything but one `<slug>/` folder, the main file is missing, or `build-info.json` is missing or has another version | `slug`, `main_file` |
| `php_lint` | any shipped `.php` file fails `php -l` (the kit's PHP, 8.x; PHP 7.4 compatibility is phpcs/PHPCompatibility's job) | — |
| `phpcs` | the configured command exits non-zero (severity `warn` by default) | `phpcs.cmd` with `{dir}`, `phpcs.severity` |
| `plugin_check` | Plugin Check reports errors in the `plugin_repo` category (runs in an isolated branchpress site; `warn` when it cannot run) | `plugin_check` (default: on for `wporg`) |
| `versions` | a shipped version location disagrees with the build's version | `version_files` |
| `headers` | `Tested up to`, `Requires PHP`, `Requires at least` (and `WC tested up to` for WooCommerce plugins) are missing from both readme.txt and the plugin header; for `wporg`, readme.txt missing or `Stable tag` ≠ version. Severity `fail` for wporg, `warn` otherwise | `required_headers`, `gate_severity.headers` |
| `contents` | the zip ships `node_modules/`, VCS folders, `.github/`, tests, phpunit/phpcs/eslint/vite/tsconfig/.env/.distignore files, `.claude/`/`CLAUDE.md`/`plans/`, source maps, nested zips, JS lockfiles or `vendor/bin/` | `zip_rules.allow`: a category name (`lockfiles`) or a path regex |
| `edition_rules` | a `must_not_contain` path or text is present, or a `must_contain` path or text is absent; a rule with `"severity": "warn"` only warns (`warn` when no rules are configured) | `zip_rules.must_not_contain` / `must_contain`: `[{path} or {content, files}, why, severity}]` |
| `shared_code` | the shared code in the zip (e.g. `vendor-prefixed/src/AdminShell.php` VERSION) is not the pinned revision; `warn` when the pin itself has a known problem (tag ≠ reported VERSION) | `shared_code[].version_file`, `version_pattern` |
| `size` | `warn` when the zip size moved more than `settings.size_jump_pct` (default 25%) from the previous build of the edition | `settings.size_jump_pct` |
| `extra:<id>` | a repo validator exits non-zero (e.g. `php scripts/validate-build.php {zip} lite`). It runs on the raw zip the repo script made (`on: raw`, default) because the repo's validator does not know `build-info.json` | `extra_gates[] {id, cmd, label, on}` with `{zip} {dir} {version} {worktree}` |

Skip a gate for one run only with `--skip-gates=<id,…>`, and say so in the report.
