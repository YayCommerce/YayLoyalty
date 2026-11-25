## Sprint 1 (2026-11-13 → 2026-11-26)

Goal: Merchants can match campaign and reward conditions by any or all (1.1.0).

Closed: 2026-11-25 · 8/8 dev-days

### R-1 Advanced rules: match any or all conditions
- status: done
- size: L
- editions: pro
- why: requested 2026-11-13 for 1.1; campaign conditions today are one flat list, and lifetime spend, order count and lifetime points conditions are declared but not offered (includes/Enums/CampaignConditionType.php)
- acceptance: A merchant sets a campaign's or reward's conditions to match all or any of them (e.g. lifetime spend >= $500 OR order count >= 5) and it applies only to matching customers; an existing flat condition list keeps working unchanged after upgrade, as all
- target: 1.1.0
- started: 2026-11-13
- built: 2026-11-13
- merged: 2026-11-13

#### Sprint review
Done: R-1 Advanced rules: match any or all conditions (#189, tests #191). Also finished inside this sprint's dates but counted in their own sprints: R-2 (#207), R-3 (#194), R-4 (#197–#200), R-5 (#203), R-6 (#202), R-7 (#211, with B-3 #213 and P-11 #214).
Slipped: none. Closed a day early (2026-11-25) with nothing open.
Keep: fixes for unreleased work stacked on the feature branch (B-3 #213, P-11 #214 into P-10), so `dev` only ever got the finished feature.
Keep: a manual and edge-case run on the worktree's own branchpress site before merging: it found B-3 and five design gaps the specs had missed.
Keep: every new regression test proven to fail with its change switched off.
Change: check a roadmap item against what already ships before planning it: R-6 asked for exports the Customers page had, and R-7 missed that the admin couldn't create its rewards.
Change: run the Lite build check with `YAYLOYALTY_WP_CLI` so the headless boot runs: a stripped License interface fataled Lite on activation and the release check passed (repeat).
Change: `/yay:wporg` writes its review into `release/`, which the edit guard blocks (repeat, kit).
