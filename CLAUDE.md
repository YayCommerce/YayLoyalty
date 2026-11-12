# YayLoyalty — Points,  Rewards,  Referral & VIP for WooCommerce

<!-- yay:managed:start v=0.8.0 -->
<!-- Kit-managed by the yay plugin 0.8.0. Don't edit this block; run /yay:harness-update. Project knowledge goes in the yay:project block below. -->
## How we work here

This repo uses the **yay** harness. Work through its skills so every developer and every Claude session follows the same flow:

| Step | Skill | Writes |
|---|---|---|
| Not sure? Say it in plain words | `/yay:go` | nothing: shows what's next, or picks the skill below |
| Plan the horizon | `/yay:roadmap` | the roadmap (`R-<n>`): one file per sprint, a list per version |
| Explore an idea | `/yay:brainstorm` | `docs/brainstorms/` (`BS-<n>`) |
| Design a UI | `/yay:design` | `docs/designs/` (`UX-<n>`) |
| Decide the work | `/yay:plan` | `docs/plans/` (`P-<n>`) |
| Build it | `/yay:implement` | worktree, branch, commits, PR |
| Run a whole queue | `/yay:autopilot` | all of the above per item, one decision sheet, `.yay/runs/` |
| Find a bug's cause | `/yay:debug` | `docs/bugs/` (`B-<n>`) |
| Fix a bug | `/yay:fix` | worktree, branch, commits, PR |
| Test build for QA | `/yay:release --bundle` | `release/bundles/<slug>-<x.y.z>-rc.<n>/` |
| Ship | `/yay:release` → merge → `/yay:release --tag-only` | readme.txt changelog, `docs/releases/`, checked bundle, tag |
| Keep docs true | `/yay:knowledge-update` | this file's project block, `docs/knowledge/` |

- A worked example from roadmap to release tag, with the exact prompts, is in `docs/yay-workflow.md`.
- Each skill checks for the previous step's output and says when it's missing. You can still run any skill on its own.
- Lost? `yayh status` lists the work in flight in every worktree, most urgent first, each with the command that moves it on. `yayh next [ID]` prints just that command.
- The repo's commands, editions, branches and generated paths are in `.yay/config.json`. Read them with `yayh config get <key>`; never guess a command.
- Get IDs and slugs from `yayh id next <R|BS|P|B|BL|UX>` and `yayh slug "<text>"`.
- Git: branches are `<type>/<slug>`, commits are `type(scope): summary`, and you never commit to `dev` directly. The full rules are in `.claude/rules/yay-git.md`.
- Never hand-edit generated paths: vendor/, vendor-prefixed/, node_modules/, assets/dist/, dist/, release/. A hook blocks it.
- Product PHP runs on PHP 7.4+. `.claude/rules/yay-wordpress.md` lists what that rules out.
<!-- yay:managed:end -->

<!-- yay:project:start -->
_Project knowledge has not been written yet. Run `/yay:knowledge-init`._
<!-- yay:project:end -->
