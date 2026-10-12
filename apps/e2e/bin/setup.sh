#!/usr/bin/env bash
# Prepares the wp-env tests site (http://localhost:8889) for the specs. Safe to rerun.
set -euo pipefail

E2E_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_DIR="$(cd "$E2E_DIR/../.." && pwd)"
WP_ENV="$E2E_DIR/node_modules/.bin/wp-env"

for required in vendor/autoload.php vendor-prefixed assets/dist/admin assets/dist/storefront; do
    if [ ! -e "$PLUGIN_DIR/$required" ]; then
        echo "Missing $required: run \`composer install\` and \`./scripts/run.sh build pro\` first." >&2
        exit 1
    fi
done

cd "$E2E_DIR"

wp() {
    "$WP_ENV" run tests-cli wp "$@" 2>&1 | grep -v -e '^✔ Ran' -e '^ℹ Starting'
}

wp rewrite structure '/%postname%/' --hard
wp wc tool run install_pages --user=1
wp option update woocommerce_currency USD
wp option update woocommerce_default_country 'US:CA'
wp option update woocommerce_coming_soon no
wp option update woocommerce_enable_myaccount_registration yes
wp option update woocommerce_registration_generate_username yes
wp option update woocommerce_registration_generate_password no
wp option update woocommerce_enable_reviews yes
wp option update woocommerce_review_rating_verification_required no
wp option update woocommerce_cheque_settings '{"enabled":"yes","title":"Check payments","description":"Pay by check.","instructions":""}' --format=json
# Reviews wait for an admin, so the spec can approve and unapprove them.
wp option update comment_moderation 1
wp option update comment_previously_approved 0

wp eval-file wp-content/plugins/yayloyalty/apps/e2e/bin/setup.php

# A fresh application password for the specs' REST calls.
for uuid in $("$WP_ENV" run tests-cli wp user application-password list admin --name=e2e --field=uuid 2>/dev/null | grep -E '^[0-9a-f-]{36}$' || true); do
    wp user application-password delete admin "$uuid"
done
mkdir -p "$E2E_DIR/.auth"
"$WP_ENV" run tests-cli wp user application-password create admin e2e --porcelain 2>/dev/null | grep -E '^[A-Za-z0-9 ]{24,}$' | tr -d ' ' > "$E2E_DIR/.auth/app-password"

if [ ! -s "$E2E_DIR/.auth/app-password" ]; then
    echo 'Could not create the application password.' >&2
    exit 1
fi

echo 'wp-env tests site is ready at http://localhost:8889'
