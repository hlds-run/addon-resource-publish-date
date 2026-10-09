# Installation

Target: a XenForo 2.3.2 board with XenForo Resource Manager 2.3.2.

Install on the test forum first. See [TESTING.md](TESTING.md).

---

## 1. Get the files onto the server

### Option A: the release archive

Download `HldsRun-ResourcePublishDate-<version>.zip` from the repository's
releases and use **Admin CP → Add-ons → Install Add-on → Upload ZIP**.

The archive is built from `upload/`, so it contains an `upload/` prefix, which
is exactly what `XF\Service\AddOnArchive\ExtractorService` strips when
installing. It also contains `hashes.json`, which is what makes **File Check**
able to tell a modified file from an unmodified one afterwards.

To build it yourself:

```bash
php tools/build.php
# _releases/HldsRun-ResourcePublishDate-1.0.1.zip
```

`xf-addon:build-release` is **not** a better command here, and running it in this
repository will dirty the working tree. XenForo's exporter runs
`xf-addon:export` first, which rewrites `_data/*.xml` from the database and
repairs `addon.json` in place - it added `legacy_addon_id` and `icon` and
reformatted the `require` block on a board where none of that was wrong. The
archive it then writes is not the same one: 57 files against our 16, roughly forty
of them empty `_data` stubs for data types this add-on does not use, written to
`src/addons/HldsRun/ResourcePublishDate/_releases` rather than to the
installation's `_releases`. It is a fine command for an add-on being developed
inside an installed forum; `tools/build.php` is the one that produces the
committed, byte-stable archive this repository releases.

Installing the archive without the Admin CP works, because `xf:addon-install`
takes a path to a ZIP as its argument and runs the same validator and extractor:

```bash
php cmd.php xf:addon-install _releases/HldsRun-ResourcePublishDate-1.0.1.zip
```

Verified on XenForo 2.3.7, including the `upload/` prefix. It still asks for
confirmation, so script it with `printf 'y\n' |` or `-n` plus the answer piped in.

### Option B: copy the directory

The repository holds the add-on under `upload/`, mirroring the forum's own
`src/` layout, so deployment is a copy:

```bash
cd /path/to/repo
cp -r upload/src/addons/HldsRun /path/to/forum/src/addons/
```

Result:

```
<forum>/src/addons/HldsRun/ResourcePublishDate/
```

If you deploy this way, copy `hashes.json` too - it is committed in the
repository. Without it File Check skips the add-on rather than verifying it.

Two ways to deploy, pick one:

**Copy over SSH / rsync** - simplest, fine for a small add-on:

```bash
rsync -av upload/src/addons/HldsRun/ deploy@forum:/srv/forum/src/addons/
```

**Mount the repository into the container** - keeps the add-on in git on the
server rather than only on disk:

```yaml
volumes:
  - /srv/addon-resource-publish-date/upload/src/addons/HldsRun:/srv/forum/src/addons/HldsRun
```

Worth doing wherever the forum source is itself under version control: the code
that runs on the forum should live in git, not only in a container's writable
layer.

> After any deployment, run `php tools/check.php` in CI or before restarting the
> container. It needs no XenForo installation.

## 2. Install and enable

```bash
cd /srv/forum
php cmd.php xf:addon-install HldsRun/ResourcePublishDate
```

`addon.json` declares `require` for XF and XFRM, so the installer refuses to run
on anything older than 2.3 rather than installing an add-on that would silently do
nothing.

Verify in the Admin CP: **Add-ons → Installed add-ons → Resource publish date**
should be enabled, and **Tools → File Check** should list the add-on's files as
expected with no "unexpected content" warnings.

The file check from the command line takes the add-on as an **option**, not as an
argument, which is the one place XenForo's own documentation is wrong - it writes
`php cmd.php xf:file-check [addon_id]`, and 2.3.7 answers `No arguments expected
for "xf:file-check" command`:

```bash
php cmd.php xf:file-check --addon HldsRun/ResourcePublishDate
# Все проверенные файлы (16) присутствуют и корректны.  /  All checked files (16) are present and correct.
```

## 3. Translations

**Nothing to do.** Every shipped translation is imported
automatically when the add-on is installed, into each board language it matches.
Check **Admin CP -> Phrases** for the language to see whether it took: the values
there are the proof, not a log line.

The import writes a line to **Admin CP -> Logs -> Error log** saying what it
imported, because XenForo has no info-level log and `XF::logError()` is the only
entry point besides `logException()`. That line only appears when the install
ran in a web request, which is what installing through the Admin CP does. Install
from the command line and XenForo silently drops it - `XF\Error::logException()`
collects request data before inserting, and that throws where there is no
request, which it catches and discards. Verified on XF 2.3.7: the same
`logError()` call writes from the Admin CP and writes nothing from `cmd.php`,
with and without the force flag.

So the absence of the line means nothing either way. Check the phrases.

If you upgraded from an earlier version, the translations installed back then are
still in place. To re-import after a wording change, or to add a language the
board gained later:

```bash
php cmd.php hlds-run-rpd:import-translation          # lists languages and which file matches each
php cmd.php hlds-run-rpd:import-translation 6 --dry-run
php cmd.php hlds-run-rpd:import-translation 6
```

The listing's **Shipped translation** column is the fastest way to answer "why did
nothing appear": if it says `none`, the board's language code does not match any
file we ship. XenForo's own packs use suffixed codes - Russian is `ru-RU`, which
`ru.xml` matches - but a board running, say, `uk` gets English only until somebody
adds `_translations/uk.xml`.

Details in [TRANSLATIONS.md](TRANSLATIONS.md).

## 4. Configure

**Admin CP → Options → Resource publish date.**

The defaults are the conservative ones and are correct for most boards:

| Setting | Default | Recommendation |
|---|---|---|
| When to shift dates | only the first approval | keep; "every approval" is a deliberate policy choice |
| Resources | on | keep |
| Resource discussion threads | on | keep, unless resource threads are closed for discussion anyway |
| Minimum time in the moderation queue | 0 | raise only if instant approvals should not count |
| Excluded resource categories | empty | fill in for long-curation categories |
| Record date shifts in the moderator log | on | keep |

There is no on/off switch. To stop the add-on doing anything, disable it on
**Admin CP → Add-ons** — that is the switch XenForo owns, and it takes effect on
the next approval.

## 5. Optional: fix content that was already published

Resources approved before the add-on was installed keep their old dates. To move
them to the date they were actually approved:

```bash
# 1. always start here
php cmd.php hlds-run-rpd:backfill --dry-run

# 2. narrow it: only approvals older than 30 days, first 100 resources
php cmd.php hlds-run-rpd:backfill --dry-run --days=30 --limit=100

# 3. apply, including the discussion threads
php cmd.php hlds-run-rpd:backfill --days=30 --threads
```

The command is idempotent: it only touches resources whose stored publish date is
*older* than their approval date - a resource updated after it was approved keeps
its newer date - and it works oldest-approval-first, so running it repeatedly in
batches converges. Read
[TROUBLESHOOTING.md](TROUBLESHOOTING.md#recovering-from-a-mistake) before running
it on production.

## 6. Uninstall

```bash
php cmd.php xf:addon-uninstall HldsRun/ResourcePublishDate
```

There is no database state to clean up: the add-on creates no tables and adds no
columns. Already-shifted dates are *not* reverted, because the original values are
not stored anywhere - this is deliberate, see [BEHAVIOR.md](BEHAVAVIOR.md) §9 for
why inventing a publish-date column would be worse. Disabling the add-on on
**Admin CP → Add-ons** stops all future shifts immediately and is the recommended
first step if something looks wrong.
