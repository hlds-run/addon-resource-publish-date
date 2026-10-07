# Upgrading

Two different things get upgraded, and they interact. Read both sections before
you touch production.

---

## 1. Upgrading the add-on

### Check what changed first

```bash
git log --oneline <installed-version>..HEAD
cat CHANGELOG.md
```

Pay attention to any entry mentioning:

- a new option - it will be created with its default value; decide whether the
  default is right for this board;
- a change in the meaning of an existing option;
- a database change - version 1.x has none; if a future version adds one, the
  changelog says so and names the schema step.

### Deploy

```bash
php tools/check.php                                 # local, before anything else
rsync -av upload/src/addons/HldsRun/ user@forum:/srv/forum/src/addons/
php src/cmd.php xf:addon-upgrade HldsRun/ResourcePublishDate
```

Then verify, in this order:

1. **Add-ons → Installed add-on** shows the expected version and is enabled.
2. **Tools → File Check** reports nothing unexpected for
   `src/addons/HldsRun/ResourcePublishDate/`.
3. **Options → Resource publish date** shows all settings with the values you
   expect - an option that vanished means the data rebuild did not pick up the
   new `_data/options.xml`, which is a signal to stop and investigate.
4. `php src/cmd.php xf:addon-upgrade` is idempotent, so running it twice is safe.

### Translations after an upgrade

**Master phrases are updated automatically. Translations are not.**

If the release adds phrases, re-import each translation:

```bash
php src/cmd.php hlds-run-rpd:import-translation 6 --file=ru --dry-run
php src/cmd.php hlds-run-rpd:import-translation 6 --file=ru
```

Until you do, the new master phrases simply have no translation and XenForo falls
back to the master text. Nothing breaks; the Admin CP shows English in a few places.

### Rolling back

```bash
git checkout <previous-tag>
rsync -av upload/src/addons/HldsRun/ user@forum:/srv/forum/src/addons/
php src/cmd.php xf:addon-upgrade HldsRun/ResourcePublishDate
```

Rolling back does not un-shift dates that were already shifted. To stop future
shifts without a deploy, set `option.hldsRunRpdEnabled = off` in the Admin CP -
that is always the first thing to try, see [TROUBLESHOOTING.md](TROUBLESHOOTING.md).

---

## 2. Upgrading XenForo or XFRM

This is where an add-on like this one can break, so the add-on pins its floor
explicitly:

```json
"require": {
    "XF":  [2030010, "XenForo 2.3.0 or newer"],
    "XFRM": [2030010, "XenForo Resource Manager 2.3.0 or newer"]
}
```

### What to check after a board upgrade

Run this list every time. Each item corresponds to a specific assumption the
add-on makes.

| # | Assumption | Where it lives | How to check |
|---|---|---|---|
| 1 | `XFRM\Service\ResourceItem\Approve::onApprove()` still exists and is called after the state change | `_data/class_extensions.xml`, `XFRM/Service/ResourceItem/Approve.php` | Read `XFRM/Service/ResourceItem/Approve.php` in the new version |
| 2 | `XF\Service\Thread\ApproverService` is still the class name | `XF/Service/Thread/ApproverService.php` | **The single highest-risk item.** In 2.3 the class was renamed from `XF\Service\Thread\Approver` to `ApproverService`. An add-on built for 2.2 extending the old name installs cleanly and silently never runs |
| 3 | `last_update` is still derived from the newest visible update's `post_date` | `Service/PublishDateManager::applyResourceDate()` uses `rebuildLastUpdateInfo()` | Read `XFRM\Entity\ResourceItem::rebuildLastUpdateInfo()` |
| 4 | The moderated → visible transition is still logged as action `approve` | `Service/PublishDateManager::countApprovals()` | Read `XFRM\ModeratorLog\ResourceItem::getLogActionForChange()` and `XF\ModeratorLog\ThreadHandler::getLogActionForChange()` |
| 5 | Resource discussion threads still carry `discussion_type = 'resource'` | `PublishDateManager::THREAD_TYPE_RESOURCE` | Read `XFRM\ThreadType\ResourceItem` and `XFRM\Entity\ResourceItem::resourceMadeVisible()` |
| 6 | `XF\Service\Phrase\ImportService::importFromXml()` still reads the attributes off the node | `_translations/*.xml`, `Cli/Command/ImportTranslation.php` | Read the service |
| 7 | The option phrase titles are still `option.<id>` / `option_explain.<id>` | `_data/phrases.xml` | Read `XF\Entity\Option::getPhraseName()` |
| 8 | XFRM still forces the initial description to `message_state = 'visible'` | `Service/PublishDateManager::evaluateResourceStructure()` expects a visible update | Read `XFRM\Service\ResourceItem\Create::setupDefaults()` |
| 9 | The parent classes still declare the same abstract members | `tools/class_check.php` stubs them | Update the stubs in `tools/class_check.php`, then run `php tools/class_check.php` |

`tools/check.php` and `tools/class_check.php` cannot check any of these - they
have no XenForo installation. They are a reading exercise, and they are the
reason this list exists.

Row 9 is the one that bit this add-on. `Setup.php` extended
`XF\AddOn\AbstractSetup` without the three step-runner traits, which `php -l`
cannot see and XenForo does not check until the data-rebuild job loads the class.
The class-loading check now covers it, and its stubs are the one piece of this
tooling tied to a specific XenForo version.

### After checking

1. Deploy and upgrade the add-on on the **test** board first.
2. Run the checklist in [TESTING.md](TESTING.md).
3. Only then deploy to production.

### If a class was renamed

The failure mode is silent, so guard against it explicitly:

- Update the `to_class`/`from_class` path in the add-on;
- update the file's namespace;
- re-run `php tools/check.php` - it checks that a
  `class_extensions.xml` `to_class` has a matching file, and that every file's
  namespace matches its path;
- because a rename would leave the *old* `from_class` in place, double-check that
  the extension list still contains exactly the two expected entries.

---

## Compatibility policy

| XF version | Supported |
|---|---|
| 2.0, 2.1, 2.2 | No. The approval services had different class names, and `require` blocks installation. |
| 2.3.x | Yes, targeted and tested against 2.3.2 |
| 2.4+ | Not yet verified. Apply the checklist above. If it passes, widen the `require` floor comment and note it in `CHANGELOG.md`. |
