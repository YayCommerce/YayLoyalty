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
