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
# _releases/HldsRun-ResourcePublishDate-1.0.0.zip
```

If you have a XenForo installation to hand, `php src/cmd.php xf-addon:build-release
HldsRun/ResourcePublishDate` is the better command: it runs XenForo's own
exporter. Both produce the same archive.

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
php src/cmd.php xf:addon-install HldsRun/ResourcePublishDate
```

`addon.json` declares `require` for XF and XFRM, so the installer refuses to run
on anything older than 2.3 rather than installing an add-on that would silently do
nothing.

Verify in the Admin CP: **Add-ons → Installed add-ons → Resource publish date**
should be enabled, and **Tools → File Check** should list the add-on's files as
expected with no "unexpected content" warnings.

## 3. Translations

**Nothing to do.** Since 1.1.0 every shipped translation is imported
automatically when the add-on is installed, into each board language it matches.
Check **Admin CP -> Logs -> Error log** for what was imported; if the board has no
matching language, it says so and the add-on stays English.

If you upgraded from an earlier version, the translations installed back then are
still in place. To re-import after a wording change, or to add a language the
board gained later:

```bash
php src/cmd.php hlds-run-rpd:import-translation          # lists languages and which file matches each
php src/cmd.php hlds-run-rpd:import-translation 6 --dry-run
php src/cmd.php hlds-run-rpd:import-translation 6
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
| Shift publish dates when content is approved | on | keep on |
| When to shift dates | only the first approval | keep; "every approval" is a deliberate policy choice |
| Resources | on | keep |
| Resource discussion threads | on | keep, unless resource threads are closed for discussion anyway |
| Minimum time in the moderation queue | 0 | raise only if instant approvals should not count |
| Excluded resource categories | empty | fill in for long-curation categories |
| Record date shifts in the moderator log | on | keep |

## 5. Optional: fix content that was already published

Resources approved before the add-on was installed keep their old dates. To move
them to the date they were actually approved:

```bash
# 1. always start here
php src/cmd.php hlds-run-rpd:backfill --dry-run

# 2. narrow it: only approvals older than 30 days, first 100 resources
php src/cmd.php hlds-run-rpd:backfill --dry-run --days=30 --limit=100

# 3. apply, including the discussion threads
php src/cmd.php hlds-run-rpd:backfill --days=30 --threads
```

The command is idempotent: it only touches resources whose stored publish date is
*newer* than their approval date, and it works oldest-approval-first, so running
it repeatedly in batches converges. Read
[TROUBLESHOOTING.md](TROUBLESHOOTING.md#recovering-from-a-mistake) before running
it on production.

## 6. Uninstall

```bash
php src/cmd.php xf:addon-uninstall HldsRun/ResourcePublishDate
```

There is no database state to clean up: the add-on creates no tables and adds no
columns. Already-shifted dates are *not* reverted, because the original values are
not stored anywhere - this is deliberate, see [BEHAVIOR.md](BEHAVAVIOR.md) §9 for
why inventing a publish-date column would be worse. Disabling the add-on
(`option.hldsRunRpdEnabled = off`) stops all future shifts immediately and is the
recommended first step if something looks wrong.
