# Store and send datetimes in UTC

**Purpose:** why every stored and transmitted datetime is UTC, and the site's time zone applies only at the edges.

**Update when:** never edit it. Supersede it with a new record if any datetime is stored in another zone.

## Status

Accepted, 2026-10-01, by Trace Bui, who confirmed the reasons. Backfilled: the decision was made on 2026-09-09, in `b6a4bda`.

## Context

- WordPress offers code two clocks: `current_time( 'mysql' )` returns site time, while `gmdate()` returns UTC.
- Loyalty rules compare stored times with now: campaign and reward windows, daily caps, report ranges and expiry dates.
- "A site-time value surfaces months later as an off-by-hours expiry" (`includes/Utils/Dates.php`).

## Decision

- Every stored `DATETIME` is UTC, as `Y-m-d H:i:s`, written through `includes/Utils/Dates.php`. Nothing stores `current_time()`.
- REST sends UTC, and the SPA converts to and from the site's zone for display and input.
- The site's time zone is used only to find a site-calendar day, as reports and daily caps need. The day's bounds go back to UTC before any query.

## Consequences

- Stored times compare and subtract without conversion.
- Every display and date input has to convert. A missed conversion shows a time that is off by the site's offset.
- "Today" takes care: it is a site-calendar day, found through `Dates::site_day_start()`, and a day with a daylight-saving change isn't 24 hours long.
- The working rules are in [conventions.md](../conventions.md#dates-and-time-zones).
