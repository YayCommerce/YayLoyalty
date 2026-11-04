---
paths:
  - "scripts/**/*"
  - "scoper.inc.php"
---

<!--
Purpose: rules for changing the build and release tooling in scripts/ and the php-scoper config, scoper.inc.php. Loads when Claude reads a matching file.
Update when: run.sh gains or loses a step, a shipped path, a prompt or a version location; the validator gains a check or a copy of an SPA string; or admin-shell's sync changes what it regenerates.
-->

# Release tooling

What the release does, and what the validator checks, is in [docs/architecture.md § Build and release](../../docs/architecture.md#build-and-release). The commands are in `CLAUDE.md`.

- **`scoper.inc.php` is generated.** `./scripts/run.sh shell-update` rewrites it from a template in admin-shell's own scripts (`vendor/bin/yaycommerce-update`), keeping only the `prefix` and the adapter class. Any other edit is lost on the next run, so change the template upstream, in admin-shell.
- **`run.sh` runs under macOS's `/bin/bash` 3.2 and BSD tools, as well as GNU.** Use no bash 4 features, such as `mapfile`, `declare -A` or `${x,,}`. Edit files in place only through `sed_inplace`.
- **Every prompt needs a way around it.** `set -e` is on, so a `read` that reaches the end of input ends the run with exit 1. Without a terminal it also prints no prompt, so the run just stops. `release` skips its prompt when given a version or `--no-prompt`; give any new prompt the same escape.
- **`scripts/lite-admin-shell.patch` is made against `vendor-prefixed/`.** When an admin-shell update changes `AdminShell.php`, `Menu/PagesRouter.php` or `Menu/PluginSubmenu.php`, the Lite release stops with "no longer applies". Regenerate the patch from the new shell (or take YayCheckout's `scripts/free-admin-shell.patch` with `YayCheckoutScoped` replaced by `YayLoyaltyScoped`), and keep `LITE_SHELL_STRIP` in `run.sh` and `$shell_strip` in the validator the same list.
- **The zip holds only what `build_package` in `scripts/run.sh` copies.** A new runtime file outside its include list must be added to that list. A dev-only file inside a shipped directory must be deleted in its strip step, and added to the validator's dev-artifact check, which mirrors that step except for `.gitkeep`.
- **The version lives in three lines:** the plugin header's `Version:` and the `YAYLOYALTY_VERSION` define in `yayloyalty.php`, and `Stable tag:` in `readme.txt`. `update_version` writes all three with `sed`. `get_version` reads the define back, and the validator reads the header and the define, each with its own pattern. Change a line's shape, or add a location, in every one of them at once.
- **The validator copies SPA strings.** `PRO_BUNDLE_SENTINEL` comes from `apps/admin/src/pro/index.ts` and `UPSELL_MARKER` from `apps/admin/src/components/pro/UpgradeToPro.tsx`. `check_inline_pro_field_gates()` reads one Design file by path and skips it when it is missing, so a rename turns that check into a silent pass. When either side changes, change both in the same commit.

## Checking a change

- phpcs doesn't scan `scripts/`. Run `bash -n scripts/run.sh`, and `php -l` on each PHP script you changed.
- Try a validator change on a zip already in `release/`: `php scripts/validate-build.php release/<zip> <pro|lite>`. It extracts into the system temp directory and changes nothing in the repo.
- Run `release` only to cut a release. It writes a new version into `yayloyalty.php` and `readme.txt`, and rescopes admin-shell into the staged copy only; the plugin folder's `vendor/` and `vendor-prefixed/` are left as they were.
