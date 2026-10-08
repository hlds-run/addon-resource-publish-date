# Testing

There is no automated test suite, and that is a deliberate choice worth defending:
XenForo has no unit-test harness, class extensions cannot be exercised without a
booted application, and a "test" that mocks `XFRM\Entity\ResourceItem` would test
the mock. What we do instead is a manual checklist that runs in minutes against a
real test board, plus the static checks in `tools/`.

**Never install on production first.** Use a staging forum, or a copy of
production's database.

---

## 1. Static checks (no forum needed)

```bash
php tools/check.php
```

Plus, in CI: `php -l` against PHP 7.4 and the runner's own PHP, XML
well-formedness, and `addon.json` validity.

`check.php` catches the silent-failure modes: extensions pointing at missing
classes, namespaces not matching their path, phrases used from PHP but absent
from `_data/phrases.xml`, translations out of sync, options referenced but not
defined.

## 2. Install on the test board

Follow [INSTALL.md](INSTALL.md). Confirm:

- [ ] Add-on installs and enables without a requirement error
- [ ] **Tools → File Check** shows no unexpected content for the add-on
- [ ] **Options → Resource publish date** exists and lists all eight settings,
      including the backfill buttons and (with advanced options shown) the batch
      size
- [ ] `php src/cmd.php hlds-run-rpd:import-translation` lists languages, and the
      Russian row shows a shipped translation rather than `none`
- [ ] **Admin CP → Phrases** for the Russian language shows Russian text for
      `option.hldsRunRpdScope`, without the CLI having been run - install
      imports translations on its own. **This is the check that carries the whole
      release.** Do not substitute the error-log line: installed from the Admin CP
      it appears, installed from `cmd.php` XenForo discards it silently, so its
      absence means nothing (see TRANSLATIONS.md, "How to tell whether it worked")
- [ ] `php src/cmd.php hlds-run-rpd:import-translation <id> --dry-run` reports a
      phrase count equal to the master count
- [ ] `php src/cmd.php hlds-run-rpd:backfill --dry-run` runs and writes nothing

The last three run commands that only fire on a forum, and one of them broke
`php src/cmd.php` for *every* command on the board. Run all three.

Install from a **clean** state to run this section: uninstall the add-on, delete
`src/addons/HldsRun/`, and install from the built archive. Reinstalling over an
existing install leaves the phrases from the previous install in place, so the
phrase check passes for the wrong reason.

## 3. The core scenario: a resource moderated, then approved

Record the starting values first:

```sql
SELECT resource_id, resource_state, resource_date, last_update
FROM xf_rm_resource
WHERE resource_id = <id>;
```

Then, as a user without `submitWithoutApproval` (or in a category with
"Always moderate resources" ticked):

- [ ] Submit a resource, note its `resource_date` and `last_update`
- [ ] Confirm it sits in the approval queue
- [ ] Note that **Latest posts**, **What's new → Posts** and the resource thread
      show nothing about it yet
- [ ] Approve it from the queue
- [ ] Re-run the SQL: `resource_date` **unchanged**, `last_update` moved to the
      approval time
- [ ] The resource appears at the top of the "Latest resources" widget
- [ ] The resource is first in `/resources/` (default order is `last_update`)
- [ ] **What's new → Resources** contains it
- [ ] It is first in its category's resource list
- [ ] The category's "last resource" row in the sidebar points at it
- [ ] The resource page shows "First release" = the **original** submission date
- [ ] The moderator log contains both an `approve` entry and a
      `publish_date_bump` entry, with the new phrase rendered

## 4. Negative paths

Each of these must leave dates untouched.

- [ ] Approve a resource twice (unapprove → approve): `last_update` does **not**
      move again
- [ ] Add the resource's category to the exclusions, approve a new one: no shift
- [ ] Set *Minimum time in the moderation queue* to 60, submit and approve
      immediately: no shift; submit again and wait: shift happens
- [ ] Turn *Resources* off, approve a thread: the resource does not shift but its
      thread does
- [ ] Turn the whole add-on off, approve a resource: nothing changes at all

## 5. Thread handling

- [ ] A resource in a category with a discussion forum produces a thread
- [ ] Approving the thread shifts `xf_post.post_date`, `xf_thread.post_date` and
      `xf_thread.last_post_date`
- [ ] The thread appears in "Latest posts" and **What's new → Posts**
- [ ] A thread with `discussion_type` other than `resource` is never touched -
      moderate and approve an ordinary thread and confirm nothing moved
- [ ] If the resource thread already has a reply (post something, then moderate and
      approve the thread): no shift, and the reason is
      `thread already has replies`

## 6. Invariant after a counter rebuild

This is the one that catches the most important bug class - see
[BEHAVIOR.md](BEHAVIOR.md) §3.

- [ ] Approve a resource, then run
      `php src/cmd.php xf-rebuild:xfrm-resource-items`
- [ ] `last_update` is **still** the approval time, not the submission time
- [ ] Repeat using the Admin CP path (Maintenance → rebuild resource items) to
      cover the ACP code path

If this fails, the update's `post_date` is not being written and the shift will
revert on the next rebuild.

## 7. Backfill

On the test board, create a resource, moderate it, approve it, then restore the
old dates by hand:

```sql
UPDATE xf_rm_resource SET last_update = <old> WHERE resource_id = <id>;
UPDATE xf_rm_resource_update SET post_date = <old>
 WHERE resource_update_id = <description_update_id>;
```

- [ ] `hlds-run-rpd:backfill --dry-run` lists it and writes nothing (re-check with
      the SQL above)
- [ ] `hlds-run-rpd:backfill` moves it and reports counts
- [ ] A second run reports "nothing to do"
- [ ] `--threads` also moves the resource's discussion thread
- [ ] `--days=30` skips a resource approved five minutes ago
- [ ] A resource in an excluded category is skipped
- [ ] `--limit=1` stops after one resource

### The same work, from the Admin CP

Same fixture: restore the dates with the SQL above, then in **Options → Resource
publish date** use the buttons at the bottom of the group.

- [ ] Only *Preview next batch* is there to begin with, and no "move" button
- [ ] **Preview** reports a count and writes nothing (re-check with the SQL above)
- [ ] **Move these dates now** appears, next to *Discard preview*
- [ ] Discarding removes it again
- [ ] Moving reports how many moved, how many skipped, and whether more are waiting
- [ ] The move button is gone after the run, and re-clicking the old link does
      nothing (copy the URL before clicking and try it twice)
- [ ] Reloading the options page does not resurrect a spent confirmation
- [ ] A second administrator's preview does not invalidate yours, and their run
      link does not run your batch
- [ ] With **Resource discussion threads** off, the thread is not touched
- [ ] Setting the batch size to 1 moves exactly one resource per click
- [ ] The buttons behave the same with the add-on's Russian translation installed

## 8. Failure handling

- [ ] With moderator logging as-is, an approval from the queue works
- [ ] The moderator log entry does not produce a PHP notice or an entry in the
      error log
- [ ] Backfill with `--log` outside a web context behaves as documented (it should
      not be used; confirm the option defaults to off)
- [ ] A backfill run from the buttons with moderator logging on writes a
      `publish_date_bump` entry per resource and does not throw

## 9. Performance sanity

- [ ] Approving a resource does not visibly slow the queue action
- [ ] No new slow-query log entries
- [ ] Error log stays clean

---

## Adding a test case

Add it to this file as a checkbox with a short precondition. Keep the SQL snippets
inline. When a bug is found, add the failing case here *before* fixing it, so the
list keeps the scar tissue that makes it useful.
