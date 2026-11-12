#!/usr/bin/env bash
# Seeds the wp-env site. wp-env runs it after every start and reset, so each
# step must be safe to repeat.
set -euo pipefail
cd "$(dirname "$0")/.."
wp() { ./node_modules/.bin/wp-env run cli wp "$@"; }

wp plugin activate yayloyalty
wp rewrite structure '/%postname%/' --hard
wp option update blogname 'YayLoyalty — Points,  Rewards,  Referral & VIP for WooCommerce E2E'
wp user get customer >/dev/null 2>&1 || wp user create customer customer@example.com \
	--role=customer --user_pass=password --first_name=Jane --last_name=Smith

wp option update woocommerce_coming_soon no
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
wp option update woocommerce_default_country 'US:CA'
wp option update woocommerce_store_address '1 Test Street'
wp option update woocommerce_store_city 'San Francisco'
wp option update woocommerce_store_postcode '94110'
wp option update woocommerce_currency 'USD'
wp wc tool run install_pages --user=admin >/dev/null
wp wc payment_gateway update cod --enabled=true --user=admin >/dev/null
if [ "$(wp post list --post_type=product --name=e2e-simple-product --format=count)" = "0" ]; then
	wp wc product create --name='E2E Simple Product' --slug=e2e-simple-product --regular_price=10 --user=admin >/dev/null
fi

# Plugin-specific data the specs need (settings, pages, products) goes below.
# AdminShell shows its Licenses page instead of the SPA until a key is stored. A placeholder: WP_HTTP_BLOCK_EXTERNAL
# (.wp-env.json) keeps it from ever reaching the licence server.
wp option update yayloyalty_license_key e2e-placeholder
wp option update yayloyalty_license_info '{"license":"valid","expires":"lifetime"}' --format=json
# The first-run wizard covers every admin screen until it is finished or exited.
wp option update yayloyalty_setup_completed 1
# Virtual, so checkout needs no shipping zone.
wp wc product update "$(wp post list --post_type=product --name=e2e-simple-product --field=ID)" --virtual=true --user=admin >/dev/null
# Block and classic checkout prefill the customer's billing address.
wp wc customer update "$(wp user get customer --field=ID)" --user=admin --billing='{"first_name":"Jane","last_name":"Smith","address_1":"1 Test Street","city":"San Francisco","state":"CA","postcode":"94110","country":"US","email":"customer@example.com","phone":"5550100"}' >/dev/null
