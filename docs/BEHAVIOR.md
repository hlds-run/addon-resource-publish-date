# How XenForo and XFRM actually date content

This is the document to read before changing anything in this add-on. It explains
the mechanism, not just the symptom.

All source references are to the versions this add-on targets:
**XenForo 2.3.2** and **XenForo Resource Manager 2.3.2**.

---

## 1. The three layers of "when"

| Layer | Where it lives | What it answers |
|---|---|---|
| Approval queue | `xf_approval_queue` (`content_date`) | When did this content enter moderation? |
| Moderator log | `xf_moderator_log` (`log_date`) | What did a moderator do, and when? |
| Content date columns | `xf_rm_resource.last_update`, `xf_rm_resource_update.post_date`, `xf_thread.last_post_date`, ... | When should this content be considered new? |

Only the third layer decides what a visitor sees as "new". The first two are
bookkeeping. This add-on reads the second and writes the third.

---

## 2. The resource date columns

`XFRM\Entity\ResourceItem::getStructure()` defines the date fields:

```php
'resource_date' => ['type' => self::UINT, 'default' => \XF::$time, 'api' => true],
'last_update'   => ['type' => self::UINT, 'default' => \XF::$time, 'api' => true],
'icon_date'     => ['type' => self::UINT, 'default' => 0],
```

There is **no `publish_date` column**. Any design that assumes one is wrong. The
public XFRM API schema agrees: [XFRM_ResourceItem](https://docs.xenforo.com/api/schemas/xfrm-resourceitem)
exposes `resource_date`, `last_update` and `icon_date`, nothing else.

Related dates live on other tables:

| Table / entity | Field | Meaning |
|---|---|---|
| `xf_rm_resource_update` (`XFRM\Entity\ResourceUpdate`) | `post_date` | When the description or a changelog entry was posted |
| `xf_rm_resource_version` (`XFRM\Entity\ResourceVersion`) | `release_date` | When a file version was released |
| `xf_thread` | `post_date`, `last_post_date` | Discussion thread dates |
| `xf_post` | `post_date` | Individual post dates |

---

## 3. `last_update` is a derived field, not an event log

This is the single most important fact about the Resource Manager.

`XFRM\Entity\ResourceItem::rebuildLastUpdateInfo()`:

```php
$lastUpdate = $this->db()->fetchRow("
    SELECT *
    FROM xf_rm_resource_update
    WHERE resource_id = ?
        AND message_state = 'visible'
    ORDER BY post_date DESC
    LIMIT 1
", $this->resource_id);
...
$this->last_update = $lastUpdate ? $lastUpdate['post_date'] : $this->post_date;
```

And `XFRM\Entity\ResourceItem::updateAdded()` keeps it in step when an update
becomes visible:

```php
if ($update->post_date >= $this->last_update)
{
    $this->last_update = $update->post_date;
}
```

So `last_update` means: *the post date of the newest visible resource update*.
Nothing else. It is not "when the resource was last touched by anybody".

**Consequence for this add-on:** writing `last_update` alone is not enough. The
next counter rebuild - ACP → Maintenance → rebuild resource items, the
`x:rm:rebuild-resource-items` CLI command, or any code path calling
`rebuildCounters()` - would recompute it from the update's `post_date` and undo
the change. That is why `applyResourceDate()` writes both fields, in that order,
and re-reads the resource through `rebuildLastUpdateInfo()` rather than assuming
the value.

The same class of bug has bitten another add-on:
[Bump Thread](https://xenforo.com/community/resources/bump-thread.8128/) shipped
a patch specifically titled *"do not bump the date of the first thread post if
there is an invisible post after it"*, to avoid moving a post during rebuilding
and thereby changing the timestamp it had just set. If dates here ever revert,
the thread's first post is the first place to look.

---

## 4. Approval changes visibility, never dates

Every approval handler in core and in XFRM is the same shape.

`XFRM\Service\ResourceItem\Approve::approve()`:

```php
public function approve()
{
    if ($this->resource->resource_state == 'moderated')
    {
        $this->resource->resource_state = 'visible';
        $this->resource->save();

        $this->onApprove();
        return true;
    }

    return false;
}
```

`onApprove()` sends notifications. That is all.

The same is true of:

- `XF\Service\Post\ApproverService::approve()` - sets `message_state = 'visible'`
- `XF\Service\Thread\ApproverService::approve()` - sets `discussion_state = 'visible'`
- `XFRM\ApprovalQueue\ResourceVersion::actionApprove()` - `quickUpdate($version, 'version_state', 'visible')`

This is deliberate. The behaviour has been
[raised upstream and declined](https://xenforo.com/community/threads/approving-new-threads-should-automatically-post-them-as-recent.211395/),
on grounds worth reading in full because they shape this add-on's defaults:

1. *A thread awaiting approval is not necessarily a new thread* - it might be an
   old one that was unapproved. Its timestamp moving on approval would be
   confusing.
2. *The first post may contain relative time references* ("four months in the
   making"), which stop making sense if the date is rewritten.
3. *Timestamps may be legally relevant*, for copyright first-publication and for
   diagnosing problems later.

Point 1 is why the default scope here is **only the first approval**, not every
approval: it is the same objection, answered the only way that makes sense when
you are going to rewrite the date anyway. Point 2 is an argument the add-on
cannot satisfy at all - rewriting the date is the whole point - so the honest
position is that it is a trade-off the administrator accepts by installing, not a
problem to solve.

The same thread also shows the consensus this add-on follows: re-approval should
not move the timestamp again.

Third-party add-ons move dates around the same way, and are linked from that
discussion: [Approval queue thread date](https://xenforo.com/community/resources/approval-queue-thread-date.9010/)
re-dates on approval, while [Change resource date](https://www.xf2addons.com/resources/change-resource-date.428/)
and [Bump Thread](https://xenforo.com/community/resources/bump-thread.8128/) are
manual. The manual ones are not a substitute for this add-on, for two reasons:

* the behaviour depends on a moderator remembering, so it differs between staff
  members and is applied inconsistently;
* they generally cannot rewrite a resource that already has updates, because
  `last_update` is derived from the newest visible update rather than stored
  independently. That is exactly the part this add-on has to get right - see the
  next section.

---

## 5. Which mechanisms read `last_update`

Moving this one field is what makes a resource resurface, because essentially
every "recent content" surface in the RM is keyed on it:

| Surface | Code | Sort / filter |
|---|---|---|
| Resource list `/resources/` | `XFRM\Finder\ResourceItem::useDefaultOrder()` | `xfrmListDefaultOrder`, default `last_update`, descending |
| Widget "Latest resources" | `XFRM\Widget\NewResources::render()` | `->order('last_update', 'desc')` |
| **What's new → Resources** | `XFRM\FindNew\ResourceItem::getResultIds()` | `where('last_update', '>', \XF::$time - 86400 * readMarkingDataLifetime)`, `order('last_update', 'DESC')` |
| Activity summary block | `XFRM\ActivitySummary\LatestResources` | `where($condition, '>', $this->getActivityCutOff())` |
| Category "last resource" row | `XFRM\Entity\Category::resourceAdded()` | compares against `last_update` |
| Sitemap `lastmod` | `XFRM\Sitemap\ResourceItem::getEntry()` | `'lastmod' => $record->last_update` |

Two fields deliberately stay on `resource_date` and are therefore *not* affected:

- `XFRM\Search\Data\ResourceItem::getResultDate()` - search result ordering
- `XFRM\Entity\ResourceItem::getContentDateColumn()` - activity log / trending content

That is the desired outcome: search and trending should reflect when content
existed and accrued its engagement, not when a moderator clicked a button.

### The `readMarkingDataLifetime` cliff

`XFRM\FindNew\ResourceItem::getResultIds()` filters by date, not by visibility:

```php
->where('last_update', '>', \XF::$time - (86400 * \XF::options()->readMarkingDataLifetime))
```

With the default 30 days, a resource that sat in the queue for more than a month
is invisible in **What's new** forever, even after approval. This is the strongest
argument for the add-on: it is not a cosmetic ranking issue, it is total
exclusion from that surface.

---

## 6. The discussion thread is a second, separate approval

When a resource is created in a category that has a discussion forum,
`XFRM\Service\ResourceItem\Create::setupResourceThreadCreation()` does:

```php
$thread->discussion_state = $this->resource->resource_state;
```

so a moderated resource gets a **moderated thread**, and that thread needs its own
approval. This is the well known
["a resource generates two or three approvals"](https://xenforo.com/community/threads/adding-or-updating-a-resource-takes-two-or-three-approvals.144061/)
behaviour, where XenForo's answer is that they are different content types and
each requires its own approval.

Two consequences for this add-on:

1. The thread's dates are never touched by the resource's approval, so the thread
   also has to be handled. Hence the second class extension on
   `XF\Service\Thread\ApproverService`.
2. `XFRM\Entity\ResourceItem::resourceMadeVisible()` only reopens the thread if the
   option `xfrmResourceDeleteThreadAction['action']` is `delete`; with the default
   `close` it merely sets `discussion_open = true`. On a board using the default,
   the thread approval really is a separate action.

The thread is identified by `discussion_type === 'resource'`
(`XFRM\ThreadType\ResourceItem` registers that type; the constant
`XF\ThreadType\AbstractHandler::BASIC_THREAD_TYPE` is `discussion`, which is the
*other* end of the transition, so it must not be used as the check).

---

## 7. Why the thread is only re-dated while it has one post

`xf_post.position` defines the order of posts inside a thread, and it is derived
from `post_date`. Moving the first post's date past a later post's date would
contradict the stored ordering and could make the thread render out of order or
produce an inconsistent "go to first unread" anchor.

A thread that has replies has, by definition, already been publicly visible, so
`reply_count > 0` is also a reliable signal that this is not a first publication.
The check is cheap and index-friendly:

```php
(int) $thread->reply_count === 0
    && (int) $thread->first_post_id === (int) $thread->last_post_id
```

---

## 8. How "first approval" is detected

The default scope is "only the first approval". That means: if a moderator
unapproves, edits and re-approves a resource, its date must not move again -
otherwise established content could jump back to the top of the lists on every
review.

The signal is the moderator log. Both entity structures hard-code logging:

- `XFRM\Entity\ResourceItem` → `$structure->options = ['log_moderator' => true]`
- `XF\Entity\Thread` → same

and both handlers map the moderated → visible transition to the action `approve`:

- `XFRM\ModeratorLog\ResourceItem::getLogActionForChange()`
- `XF\ModeratorLog\ThreadHandler::getLogActionForChange()`

So counting `approve` rows for the content answers "has this ever been
published?".

Timing matters. `XFRM\Entity\ResourceItem::_postSave()` writes the moderator log
entry at the end of the same save that changes the state, and `onApprove()` runs
after that save. Therefore, when `onApprove()` runs, the current approval is
already recorded, and **exactly one row means "this is the first approval"**.
This is why `PublishDateManager` has two differently-named helpers:

- `isFirstApprovalInProgress()` - called from `onApprove()`, count must be <= 1
- `hasBeenApproved()` - called outside an approval, any row means yes

Confusing the two is the most likely way to introduce a bug here; both names are
deliberately explicit about their context.

### The one caveat

Moderator log retention is controlled by `moderatorLogLength`
(Admin CP → Options → Logging, default `0` = keep forever). If an admin prunes
the log aggressively, old `approve` rows disappear and a re-approval can be
mistaken for a first approval. Documented in
[TROUBLESHOOTING.md](TROUBLESHOOTING.md); switching the scope to "every
approval" is a valid alternative policy if the board does not care about
moderator log retention.

---

## 9. Why `resource_date` must not be touched

`resource_date` is used for things that should describe when the work was done,
not when it was released:

- "First release" on the resource page, via `xfrm_first_release`
- `dateCreated` in `getStructuredData()`, i.e. schema.org for SEO
- `XFRM\Search\Data\ResourceItem::getResultDate()`, i.e. search result dates
- `getContentDateColumn()`, i.e. activity log and trending content
- `XFRM\Entity\ResourceItem::canEditIcon()` - "may you still set the icon?" is
  true while `resource_date > XF::$time - 3 * 3600`
- `XFRM\Entity\ResourceUpdate::isDescription()` - during insert it falls back to
  `$this->post_date == $resource->resource_date`

Rewriting it would make a resource claim to have been created the day it was
approved, which is exactly the kind of quiet data corruption this add-on is
meant to avoid.

---

## 10. Paths through the add-on

```
Moderator clicks Approve
  |
  +-- approval queue (XFRM\ApprovalQueue\ResourceItem::actionApprove)
  +-- inline moderation (XFRM\InlineMod\ResourceItem, action 'approve')
  +-- queued job (XF\Job\ApprovalQueueProcess)
        |
        v
  XFRM\Service\ResourceItem\Approve::approve()
        resource_state = 'visible'; save();      <- XFRM writes visible + mod log
        onApprove()                              <- notifications
        onApprove() [ours]                       <- date shift
              |
              v
        PublishDateManager::bumpResource()
              evaluateResource()   enabled? type? category? min age? first approval? visible update? already current?
              applyResourceDate()  update.post_date = t; resource.last_update = derived; save(); mod log
```

The resource's thread takes the identical route through
`XF\Service\Thread\ApproverService::onApprove()`.
