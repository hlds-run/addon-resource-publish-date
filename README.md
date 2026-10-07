# Resource publish date

XenForo add-on that moves the **publish date** of a resource to the moment a
moderator approves it.

Without it, a resource submitted on the 1st and approved on the 15th keeps its
submission date forever. XenForo's Resource Manager sorts by `last_update`, so
that resource lands deep inside the "new resources" lists, and if it waited
longer than the *Read marking data lifetime* (30 days by default) it does not
appear in **What's new** at all.

With the add-on, approval is treated as publication: the resource becomes "new"
when it actually becomes visible.

- Add-on id: `HldsRun/ResourcePublishDate`
- Requires: XenForo 2.3.0+, XenForo Resource Manager 2.3.0+
- Licence: MIT

---

## Documentation

Read in this order; each file answers one question.

| File | Question it answers |
|---|---|
| [docs/BEHAVIOR.md](docs/BEHAVIOR.md) | Why does the problem exist, which dates are involved, and why does the add-on move exactly these fields? |
| [docs/INSTALL.md](docs/INSTALL.md) | How do I get it onto a forum, and how do I install the Russian translation? |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | What does each setting do, and what are the trade-offs? |
| [docs/TRANSLATIONS.md](docs/TRANSLATIONS.md) | How does multilingual support work here, and how do I add a language? |
| [docs/DEVELOPING.md](docs/DEVELOPING.md) | Repository conventions, code style, how to build a release, how to add a feature |
| [docs/UPGRADE.md](docs/UPGRADE.md) | How to update the add-on, how to check whether XenForo broke it |
| [docs/TESTING.md](docs/TESTING.md) | How to verify the add-on on a test forum before touching production |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Why did nothing happen? Why did it happen twice? How do I undo it? |

## Quick start

Download the archive from the repository's releases and upload it through
**Admin CP → Add-ons → Install Add-on → Upload ZIP**. That is the whole
installation; there is nothing to compile and no schema to run.

Working from a checkout instead:

```
# 1. build the release archive (writes _releases/*.zip)
php tools/build.php

# 2. or copy the add-on onto the forum directly
cp -r upload/src/addons/HldsRun <forum>/src/addons/

# 3. install it
php src/cmd.php xf:addon-install HldsRun/ResourcePublishDate

# 4. import the Russian translation (list languages first)
php src/cmd.php hlds-run-rpd:import-translation
php src/cmd.php hlds-run-rpd:import-translation 6

# 5. configure it
#    Admin CP -> Options -> Resource publish date
```

[docs/INSTALL.md](docs/INSTALL.md) has the full procedure, including the
`--dry-run` backfill for resources that were already approved before the install.

## What it does and does not touch

| Field | Action | Why |
|---|---|---|
| `xf_rm_resource.last_update` | set to the approval time | The field the RM sorts by, and the one `What's new` filters on |
| `xf_rm_resource_update.post_date` (newest visible update) | set to the approval time | `last_update` is a denormalised copy of it; changing one without the other silently reverts on the next counter rebuild |
| `xf_thread.post_date`, `xf_thread.last_post_date` | set to the approval time | Makes the resource's discussion thread show up in "Latest posts" |
| `xf_post.post_date` (first post of that thread) | set to the approval time | Same reason; only while the thread has a single post |
| `xf_rm_resource.resource_date` | **never changed** | It is the truthful creation date, shown as "First release" and emitted as `dateCreated` in structured data |
| `xf_rm_resource_version.release_date` | **never changed** | Release date is a fact about the file, not about moderation |

## Design in one paragraph

The add-on extends exactly two XenForo classes, both of them `protected
onApprove()` overrides: `XFRM\Service\ResourceItem\Approve` and
`XF\Service\Thread\ApproverService`. Those two methods are the single funnel for
every approval path - the approval queue, inline moderation and the queue job all
end up there. All logic lives in one service class, `Service\PublishDateManager`,
which returns a `BumpResult` explaining not just what happened but why, so that
the moderator log, the CLI output and this documentation can all use the same
vocabulary.

## Support

| I want to... | Where |
|---|---|
| Report a bug | [Open an issue, bug template](https://github.com/hlds-run/addon-resource-publish-date/issues/new?template=bug_report.yml) |
| Ask how to configure this | [Open an issue, configuration template](https://github.com/hlds-run/addon-resource-publish-date/issues/new?template=config_help.yml) |
| Suggest a feature | [Open an issue, feature template](https://github.com/hlds-run/addon-resource-publish-date/issues/new?template=feature_request.yml) |
| Ask why nothing happened | [Troubleshooting](docs/TROUBLESHOOTING.md) - starts with SQL that distinguishes the causes |
| Follow releases, or ask for an update | [Releases](https://github.com/hlds-run/addon-resource-publish-date/releases) and [CHANGELOG.md](CHANGELOG.md) |
| Contribute | [CONTRIBUTING.md](CONTRIBUTING.md) |

Before opening a bug report, read
[docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md). "The date did not move" has
several causes that look identical from outside, and that document tells them
apart with SQL - the answer to most such reports is already in it.

The add-on is tested against XenForo 2.3.2 and XFRM 2.3.2, and works across
2.3.x. It requires 2.3.0 or newer: in 2.3 XenForo renamed
`XF\Service\Thread\Approver` to `ApproverService`, and an add-on built against
the old name installs without error and then silently never runs.

## Licence

MIT. See [LICENSE.md](LICENSE.md).

XenForo and XenForo Resource Manager are products of XenForo Ltd. This add-on is
not affiliated with or endorsed by them, and uses only their documented
extension points.
