# YayLoyalty — Points,  Rewards,  Referral & VIP for WooCommerce end-to-end tests

Playwright tests against a throwaway WordPress site run by wp-env (Docker).

```sh
yayh run e2e-install          # once: dependencies and Chromium
yayh e2e env start            # start this checkout's site (each worktree gets its own port)
yayh e2e run                  # all specs
yayh e2e run --grep @P-4      # specs for one plan or bug
yayh e2e env reset            # wipe the site and re-seed it
```

Without the yay kit: `npm run env:start` and `npm test` in this folder (port 9190).

- `specs/`: one file per user flow. Tag each test with the plan or bug it proves (`{ tag: '@P-4' }`).
- `fixtures/test.ts`: import `test`, `expect` and `wp` from here, not from `@playwright/test`.
- `bin/setup-env.sh`: the site's seed data. It runs after every start, so keep it repeatable.
- Admin is `admin` / `password`, customer is `customer` / `password`.
