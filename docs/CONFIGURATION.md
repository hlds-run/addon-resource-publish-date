# Configuration

All settings live in **Admin CP → Options → Resource publish date**, stored in
`xf_option` with the `hldsRunRpd` prefix.

The add-on reads its options through `PublishDateManager`, never directly, so
there is exactly one place where a default is defined.

---

## Turning the add-on off

There is no on/off option, and that is deliberate. Disabling the add-on on
**Admin CP → Add-ons** unloads its class extensions, so `onApprove()` is never
called and behaviour is identical to stock XenForo — instantly, with no deploy and
no rebuild.

An on/off option in this group was considered and left out. It would have said
exactly what the add-on list already says, in a second place. Two switches for one
state is one more thing to explain when a date did or did not move, and the wrong
one to reach for under pressure: an option in this group is harder to find than a
row in the add-on list.

## `hldsRunRpdScope` — when to shift

**Default: `first`.**

| Value | Behaviour | Use when |
|---|---|---|
| `first` | Only the very first approval of a piece of content shifts its date | Default. Prevents established content from jumping back to the top on every re-review |
| `every` | Every approval shifts the date, including re-approvals | You deliberately want content to resurface whenever it passes moderation again |

Detection for `first` counts `approve` entries in the moderator log; the reasoning
and the timing subtlety are in [BEHAVIOR.md](BEHAVIOR.md) §8. If your board prunes
moderator logs aggressively (`moderatorLogLength` low), `first` can degrade into
`every` for old content. Either raise the retention or accept `every`.

## `hldsRunRpdBumpResource` — resources

**Default: on.**

Writes `xf_rm_resource_update.post_date` (newest visible update) and
`xf_rm_resource.last_update`. Turning it off means resources keep their old
behaviour while resource discussion threads are still re-dated, which is a valid
combination for boards whose resources are curated over weeks but whose threads
should be live.

## `hldsRunRpdBumpThread` — resource discussion threads

**Default: on.**

Writes `xf_post.post_date` (first post), `xf_thread.post_date` and
`xf_thread.last_post_date`, only for threads with `discussion_type = 'resource'`
and only while the thread still has a single post.

Turn it off if resource threads are closed for discussion and you do not want them
competing with real threads in "Latest posts".

## `hldsRunRpdMinimumModerationMinutes` — threshold

**Default: `0` (no threshold).**

Skips the shift when the content was submitted less than N minutes ago, measured
as `now - resource_date`.

Why this exists: an approval that takes two seconds is not a publication event, and
the shift still costs an `UPDATE` on two tables plus a moderator log row and an
alert-engine job for watchers. Raising it to, say, 60 means only content that
actually waited is treated as newly published.

It also changes how the thread check reads: for threads the age is measured
against `thread.post_date`.

## `hldsRunRpdExcludedCategories` — per-category opt-out

**Default: empty.** Stored as a JSON array of `resource_category_id`.

Rendered as a scrolling multiple select built from the live XFRM category tree
via `Option\ExcludedCategories` - the same component the core widget settings
use for their "limit to nodes" field. The first row is *All categories*, which
means nothing is excluded; it is pre-selected on a fresh install. Ids of
categories that no longer exist are dropped when you save, so the option cannot
accumulate stale entries.

Because this uses `edit_format="callback"`, changing the callback method name
requires `xf:addon-upgrade` (or a rebuild) on every board - see
[UPGRADE.md](UPGRADE.md).

The backfill command honours this list too, and re-checks it against the live
option rather than trusting its own query.

## `hldsRunRpdLogToModeratorLog` — audit trail

**Default: on.**

Writes an entry with the action `publish_date_bump` next to the approval entry in
the moderator log, with phrases `mod_log.resource_publish_date_bump` and
`mod_log.thread_publish_date_bump`.

Turn it off if you have many approvals and find the log noisy; the date shift is
still recorded implicitly, because the moderator log already contains the
approval itself with its own timestamp.

The backfill command disables this regardless of the option, because
`XF\ModeratorLog\AbstractHandler::setupLogEntityActor()` resolves the actor's IP
from the HTTP request and throws when there is none. Pass `--log` to override
deliberately.

---

## Recommended board profiles

### Standard forum with moderated resources

Keep everything at defaults. Expected effect: a resource approved today appears at
the top of the widget, at the top of `/resources/`, and in **What's new →
Resources**.

### Long-curation categories (curators review over weeks)

Keep defaults, and add the curated categories to `hldsRunRpdExcludedCategories`.
You get the benefit for user submissions in the open categories and no surprise
reordering for the curated ones.

### Board where nothing is moderated

Install nothing. The add-on is inert: `getNewContentState()` returns `visible`,
nothing enters the queue, `onApprove()` is never called.

### Board that wants instant approvals to be invisible to the add-on

Set `hldsRunRpdMinimumModerationMinutes` to a value matching your SLA, e.g. `30`.
