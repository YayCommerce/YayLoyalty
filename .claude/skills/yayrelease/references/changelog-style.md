# Changelog style

**Purpose:** the house rules for a YayLoyalty changelog entry — where it goes, how each
bullet is labelled, what earns one, and what to drop — so the `/yayrelease` skill writes an
entry a site owner can read.

**Update when:** the changelog moves out of `readme.txt`, its heading or entry format
changes, the set of bullet labels changes, dates or edition suffixes start being used, or
the rule for what earns a bullet changes.

## Where entries live

`readme.txt`, under its `== Changelog ==` heading, **newest first**. There is no
`CHANGELOG.md` in this repo, and no changelog in `README.md`.

## The format

An entry looks like this:

```
= Oct 28, 2026 - Version 3.2.3 =
* Added: birthday campaigns award points on a customer's birthday.
* Updated: the launcher now hides itself for logged-out visitors by default.
* Fixed: points are returned when an order is refunded.
```

The entire corpus is one entry, `1.0.0`, which has never been released. **Its heading is
still undated** — `= 1.0.0 =` — because there is no release date to put in it yet. The
release run fills that in; see "A heading written before its release" below. Its bullets
were retrofitted when the labels were introduced, so they are the pattern to copy. A past
entry is otherwise left alone: fix a wrong one deliberately, never in passing during a
release.

- Heading is **`= <Mon D, YYYY> - Version <X.Y.Z> =`**, as in `= Oct 28, 2026 - Version 3.2.3 =`.
  Exactly that shape: three-letter month, day with **no leading zero**, comma, four-digit
  year, then a spaced hyphen, then the word `Version` and the number. No `v` prefix, and the
  number matches the three version locations exactly — suffix and all, so `1.1.0-beta.1`
  appears as `Version 1.1.0-beta.1`.
- **The date is the release date settled once in phase 0** — today in UTC
  (`date -u +"%b %-d, %Y"`), or whatever `--date` was given, or the committed heading's own
  date on a resumed release.
  UTC because this repo stores and prints dates in UTC
  ([docs/conventions.md](../../../../docs/conventions.md)), so a release cut late in the
  evening does not get tomorrow's date for one maintainer and today's for another. Never
  read the date off a commit, a file mtime or the system clock a second time: one release,
  one date, and it is the same string in the tag message.
- Bullets are `*`, one line each.
- **Every bullet opens with one of three labels**, followed by a space:

  | Label | For |
  |---|---|
  | `Added:` | something that was not there before — a feature, a setting, a screen, an email, a block |
  | `Updated:` | existing behaviour that changed, including a changed default, a reworked screen, and anything removed (name what went) |
  | `Fixed:` | a bug a site owner could have hit |

- Sentence case after the label, ending in a full stop:
  `* Fixed: points are now returned when an order is refunded.`
- **Pick the label from what the site owner experiences, not from the commit type.** A
  refactor that happens to change a default is `Updated:`, not nothing; a fix that required
  a new setting is two bullets, `Fixed:` and `Added:`. When a bullet seems to want two
  labels, it is two bullets.
- **Order the bullets by label**: every `Added:` first, then `Updated:`, then `Fixed:`.
  Within a label, most significant first. This is mechanical on purpose — nobody should
  have to decide an order.
- No issue numbers, no commit hashes, no contributor names.
- **The one bullet with no label** is a first release's `* Initial release.` — none of the
  three fits it, and it does not get one. It sorts **first**, above the labelled bullets,
  because it is the headline of that entry rather than one item in it. See the
  first-release section below.
- **No free/pro suffix, and the label never carries the edition.** The same `readme.txt`
  ships in both the Pro and the Lite zip, so an entry about a Pro-only feature reaches
  free users too. Name the feature plainly and let the plugin's own upsell surface say it
  is Pro. If a line would mislead a free user into thinking they have something they do
  not, mark it **inside the sentence** and keep the label first —
  `* Added: birthday campaigns (Pro).`, never `* Pro: Added: …`. Flag the new marker to
  the owner rather than spreading it silently across the entry.
- Voice is the site owner's, not the developer's. "Points are now returned when an order is
  refunded", not "fixed `LedgerService` refund path".

## What earns a bullet

**If a site owner would not notice it after updating, it is not a bullet.**

Write an entry for: a new or changed earning or redemption rule, anything that changes
points or money arithmetic, a new admin screen or setting, a new storefront surface, an
email that starts or stops being sent, a fixed bug they could have hit, and any change to
defaults that alters a live site's behaviour.

**Drop entirely:** CI and workflow changes, refactors and renames with no behaviour change,
documentation, harness and skill changes, dependency and lockfile syncs, test-only commits,
build-script changes that do not change what ships, and the previous release commit itself.

## Grouping and splitting

The mapping between commits and bullets is not one to one.

- **Several commits, one bullet.** A feature built over six commits, or a fix plus its
  follow-up corrections, is one bullet describing the end state. Nobody wants to read the
  development history.
- **One commit, several bullets.** A commit that fixes two unrelated user-visible problems
  earns one bullet each.
- **A commit and its own revert** cancel out. Neither appears.

## The first release is a special case

The one entry in this repo is a **first** release, and it is two bullets for 381 commits
of history. Do not try to reproduce a first release from its commit log: the house answer
collapses everything into `* Initial release.`, plus a bullet for anything notable that
landed late enough that an early tester would not already have seen it.

This matters because the grouping rules above, applied to the whole history, would produce
dozens of bullets and would not match what shipped. Only releases **after** the first are
a commit-range exercise.

`* Initial release.` takes no label. Any bullet beside it does take one, and is almost
always `Added:`.

## Zero entries is a valid answer

If the range holds nothing user-facing, say **"nothing user-facing to release"** and stop
to ask the owner whether they still want a version. Do not invent a bullet, and do not pad
with "Various improvements and bug fixes." A release with no changelog entry is a real
situation; a fabricated entry is a lie that ships.

## Reading the commits

Read the **body** as well as the subject. The subject names the change; the body usually
carries the symptom a user noticed, which is what the bullet should describe. Squash-merge
subjects end in a PR number — drop it from the entry.
