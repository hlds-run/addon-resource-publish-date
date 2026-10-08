# Troubleshooting

Start here. Every reason the add-on declines to act has a phrase
`hlds_run_rpd_reason_*`, and that same phrase is what you see in the CLI output.

---

## "Nothing happened when I approved a resource"

Check in this order; the first three cover almost every case.

### 1. Is the add-on actually enabled?

**Admin CP → Options → Resource publish date → Shift publish dates when content is
approved.**

If it is off, *both* resource and thread handling are off. Nothing else matters
until this is on.

Also confirm the add-on itself is enabled in **Add-ons → Installed add-ons** -
options for a disabled add-on remain readable but the class extensions do not
run.

### 2. Is the content type enabled?

*Resources* and *Resource discussion threads* are separate switches. If you turned
*Resources* off, threads still shift and vice versa.

### 3. Is the category excluded?

`hldsRunRpdExcludedCategories` is a scrolling multiple select of resource
categories. If the resource's category is selected, nothing happens -
deliberately, so that curated categories keep a stable order. *All categories*
means nothing is excluded.

### 4. Is the scope "first approval" and this not the first one?

With the default scope, only the very first approval of a resource shifts its
date. If a moderator unapproved, edited and re-approved it, no shift is correct
behaviour.

To find out which case applies, count the approvals:

```sql
SELECT COUNT(*) AS approvals, MIN(log_date) AS first_approved, MAX(log_date) AS last_approved
FROM xf_moderator_log
WHERE content_type = 'resource' AND content_id = <resource_id> AND action = 'approve';
```

More than one row means you are looking at a re-approval, not the first one.

### 5. Did it not wait long enough?

If *Minimum time in the moderation queue* is set, content approved faster than
that is left alone. Check `resource_date` against the approval time.

### 6. Does the resource have a visible update?

The shift anchors on the newest update with `message_state = 'visible'`. A resource
with no visible update is skipped, and the add-on writes an entry to the error log
saying so, because for a freshly approved resource that state should be
impossible - XFRM always creates the description visible
(`XFRM\Service\ResourceItem\Create::setupDefaults()`).

```sql
SELECT resource_update_id, message_state, post_date
FROM xf_rm_resource_update
WHERE resource_id = <resource_id>
ORDER BY post_date DESC;
```

### 7. Was the content submitted in the future?

Relative dates inside a resource can say "1 January 2027". If the newest visible
update's `post_date` is already at or after the approval time, the add-on treats
the stored date as current and does nothing.

---

## "It was shifted twice"

With the default scope this should be impossible. It happens when moderator log
rows have been pruned.

```sql
SELECT option_value FROM xf_option WHERE option_name = 'moderatorLogLength';
```

`0` means keep forever. A positive value prunes rows older than that many days, so
a resource approved a year ago and re-approved today may have no surviving `approve`
row from the first approval - the add-on then reads the current approval as the
first one.

Fix, in order of preference:

1. Set *Read marking data lifetime*'s sibling option `moderatorLogLength` back to
   `0` (Admin CP → Options → Logging).
2. Switch the scope to *Every approval*. This makes the behaviour deliberate
   instead of accidentally correct, and has its own downside: content can resurface
   repeatedly.
3. Leave it, if re-approvals are rare on your board.

---

## "The date shifted but then reverted"

The most likely cause is a Resource Manager counter rebuild.
`XFRM\Entity\ResourceItem::rebuildLastUpdateInfo()` recomputes `last_update` from
the newest visible update's `post_date`.

If the update's `post_date` was written correctly the value survives a rebuild -
that is exactly why the add-on writes both fields. If you see a revert, check both:

```sql
SELECT r.last_update, u.post_date
FROM xf_rm_resource AS r
JOIN xf_rm_resource_update AS u ON u.resource_update_id = r.description_update_id
WHERE r.resource_id = <resource_id>;
```

If `last_update` and `post_date` disagree, one of two other add-ons is writing to
these rows. Disable them and re-test.

---

## "The resource thread was not shifted"

- `discussion_type` must be `resource`. `XF\ThreadType\AbstractHandler::BASIC_THREAD_TYPE`
  is `discussion` and is **not** the right check - it is the other end of the
  transition.
- The thread must still have exactly one post (`reply_count = 0` and
  `first_post_id = last_post_id`). A thread with replies has already been public,
  and moving its first post would contradict the stored post ordering.
- The thread must have been approved. Remember that approving a resource does not
  necessarily approve its thread: with the default
  `xfrmResourceDeleteThreadAction = close`, the thread stays `moderated` until a
  moderator approves it separately. See [BEHAVIOR.md](BEHAVIOR.md) §6.

```sql
SELECT t.thread_id, t.discussion_type, t.discussion_state,
       t.post_date, t.last_post_date, t.reply_count
FROM xf_thread AS t
JOIN xf_rm_resource AS r ON r.discussion_thread_id = t.thread_id
WHERE r.resource_id = <resource_id>;
```

---

## "Ordinary threads are being shifted"

They must not be, and the add-on cannot shift them: the only thread write path is
`XF\Service\Thread\ApproverService::onApprove()`, which immediately checks
`discussion_type !== 'resource'` and returns.

If you see an ordinary thread with today's date, it was shifted by something else.
The usual culprits are the manual date add-ons -
[Change resource date](https://www.xf2addons.com/resources/change-resource-date.428/)
for resources and [Bump Thread](https://xenforo.com/community/resources/bump-thread.8128/)
for threads - or
[Approval queue thread date](https://xenforo.com/community/resources/approval-queue-thread-date.9010/),
which re-dates on approval the way this add-on does.

To identify which, disable the other add-ons one at a time and reproduce, rather
than reasoning about which one is capable of it: several of them write thread
dates through paths this add-on has no involvement in, and the moderator log will
not tell you which add-on did it.

---

## CLI errors

### `Language N does not exist`

Run `php src/cmd.php hlds-run-rpd:import-translation` with no arguments to list
the languages actually on this board. Language ids are per-installation.

### `Translation file X was not found`

The file must be at
`src/addons/HldsRun/ResourcePublishDate/_translations/<file>.xml` **on the server**.
If you deployed with rsync and skipped `_translations`, that directory is missing.

### `Translation file X is not a readable phrases document`

The file is malformed XML or its root is not `<phrases>`. Validate it locally with
`php tools/check.php`. Remember that XML comments cannot contain `--`, which is a real
trap when writing examples of CLI flags inside a comment.

### Backfill says "nothing to do"

It only considers resources whose stored publish date is **newer** than their
approval date. If your old resources already look right, there is nothing to fix.
If you expected it to find something, check:

```sql
SELECT r.resource_id, r.last_update, MIN(l.log_date) AS approved
FROM xf_rm_resource AS r
JOIN xf_moderator_log AS l
  ON l.content_type = 'resource' AND l.content_id = r.resource_id AND l.action = 'approve'
WHERE r.resource_state = 'visible'
GROUP BY r.resource_id, r.last_update
HAVING approved < r.last_update
LIMIT 20;
```

Empty result: no resource qualifies. Either the moderator log has no `approve`
entries for those resources, or their dates are already at or before their
approval time.

---

## Recovering from a mistake

There is no undo button, and there is deliberately no stored copy of the old
values. What you have is the moderator log, which records both the approval and the
shift with its timestamps:

```sql
SELECT log_date, action, action_params
FROM xf_moderator_log
WHERE content_type = 'resource' AND content_id = <resource_id>
ORDER BY log_date DESC
LIMIT 10;
```

To put a resource back:

```sql
UPDATE xf_rm_resource AS r
JOIN xf_rm_resource_update AS u ON u.resource_update_id = r.description_update_id
SET r.last_update = <original timestamp>,
    u.post_date     = <original timestamp>
WHERE r.resource_id = <resource_id>;
```

Then rebuild the search index for that resource, or the search results will keep
the old date, and clear the RM's data cache:

- Admin CP → Tools → File Check is not involved;
- Admin CP → Maintenance → rebuild resource items for the counters;
- the search index is updated on next save or via
  Maintenance → rebuild search index.

Before doing any of this, read the caveat in
[BEHAVIOR.md](BEHAVIOR.md) §3: `last_update` **must** stay equal to the post date
of the newest visible update, or the next rebuild will disagree with you.

## Turning everything off without deploying

Disable the add-on on **Admin CP → Add-ons**. Its class extensions are unloaded,
so `onApprove()` is never called and the board behaves exactly like stock
XenForo. It takes effect on the very next approval, and it is the first thing to
do when something looks wrong in production.
