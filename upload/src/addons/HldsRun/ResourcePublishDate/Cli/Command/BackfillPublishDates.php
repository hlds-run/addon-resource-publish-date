<?php

namespace HldsRun\ResourcePublishDate\Cli\Command;

use HldsRun\ResourcePublishDate\Service\PublishDateManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;
use XF\Entity\Thread;
use XF\Util\Str;
use XFRM\Entity\ResourceItem;

/**
 * Re-dates resources that were approved before this add-on existed.
 *
 * The premise: a resource submitted on the 1st and approved on the 15th has been
 * sitting in the "new content" lists at its submission date ever since. The
 * moderator log still knows when it was actually approved, so that timestamp can
 * be replayed into `last_update` and into the post date of the newest visible
 * update.
 *
 * This is a mass write to content ordering, therefore:
 *   - --dry-run is available, and it is what you should always run first;
 *   - moderator log entries are off by default here, because
 *     XF\ModeratorLog\AbstractHandler::setupLogEntityActor() needs an HTTP request
 *     to resolve the actor's IP address and throws without one;
 *   - the query is bounded by --limit and ordered oldest-approval-first, so a
 *     re-run always progresses through the oldest work instead of repeating it.
 *
 * Only resources whose stored publish date is NEWER than their approval date are
 * considered, and resources in excluded categories are never touched.
 *
 * @see docs/TROUBLESHOOTING.md, "Recovering from a mistake"
 */
class BackfillPublishDates extends AbstractCommand
{
    private const DEFAULT_LIMIT = 500;

    protected function configure()
    {
        $this
            ->setName('hlds-run-rpd:backfill')
            ->setDescription(\XF::phrase('hlds_run_rpd_cli_backfill_description'))
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List what would be changed without writing anything.'
            )
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_REQUIRED,
                'Only consider approvals older than this many days. 0 disables the filter.',
                '0'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of resources to process.',
                (string) self::DEFAULT_LIMIT
            )
            ->addOption(
                'threads',
                null,
                InputOption::VALUE_NONE,
                'Also re-date the discussion thread of each resource.'
            )
            ->addOption(
                'log',
                null,
                InputOption::VALUE_NONE,
                'Write moderator log entries. Needs a web request context; off by default.'
            )
            ->setHelp(
                "Only resources whose stored publish date is newer than their approval date are touched.\n"
                . 'Resources in excluded categories are never touched, whatever the options say.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $includeThreads = (bool) $input->getOption('threads');
        $limit = max(1, (int) $input->getOption('limit'));
        $minimumAgeSeconds = max(0, (int) $input->getOption('days')) * 86400;

        /** @var PublishDateManager $manager */
        $manager = \XF::service(PublishDateManager::class);
        $manager->setLoggingEnabled(!$dryRun && (bool) $input->getOption('log'));

        $candidates = $this->findResourceCandidates($limit, $minimumAgeSeconds);

        if (!$candidates) {
            $output->writeln(\XF::phrase('hlds_run_rpd_cli_backfill_nothing'));

            return 0;
        }

        $moved = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            $resourceId = (int) $candidate['resource_id'];
            $approvedDate = (int) $candidate['approved_date'];

            /** @var ResourceItem|null $resource */
            $resource = \XF::em()->find(ResourceItem::class, $resourceId);
            if (!$resource) {
                ++$skipped;
                continue;
            }

            // Re-check against the live option rather than the query, so a
            // category added to the exclusion list after the query ran is still
            // honoured.
            if ($manager->isCategoryExcluded((int) $resource->resource_category_id)) {
                ++$skipped;
                continue;
            }

            // Sanity check: an approval cannot predate the resource it approved.
            if ($approvedDate < (int) $resource->resource_date) {
                ++$skipped;
                continue;
            }

            $previousDate = (int) $resource->last_update;

            if ($manager->evaluateResourceStructure($resource, $approvedDate) !== null) {
                ++$skipped;
                continue;
            }

            if (!$dryRun) {
                $result = $manager->applyResourceDate($resource, $approvedDate);

                if (!$result->isBumped()) {
                    ++$skipped;
                    continue;
                }
            }

            ++$moved;

            if ($includeThreads) {
                $this->processResourceThread($resource, $approvedDate, $dryRun, $manager);
            }

            $output->writeln(sprintf(
                '  #%d %s: %s -> %s',
                $resourceId,
                $this->truncateTitle((string) $resource->title),
                $this->formatTimestamp($previousDate),
                $this->formatTimestamp($approvedDate)
            ));
        }

        $output->writeln('');

        if ($dryRun) {
            $output->writeln(
                \XF::phrase('hlds_run_rpd_cli_backfill_candidates', ['count' => $moved])
            );
            $output->writeln('<comment>'
                . \XF::phrase('hlds_run_rpd_cli_backfill_dry_run')
                . '</comment>');

            return 0;
        }

        $output->writeln(
            \XF::phrase('hlds_run_rpd_cli_backfill_summary', ['moved' => $moved, 'skipped' => $skipped])
        );

        return 0;
    }

    /**
     * Visible resources that have an approval recorded, oldest approval first.
     *
     * The "stored date is newer than the approval date" test lives in the WHERE
     * clause because it compares a column against MIN(log_date), which MySQL
     * accepts in HAVING as well but reads far more clearly here.
     *
     * @return list<array{resource_id: int, approved_date: int}>
     */
    private function findResourceCandidates(int $limit, int $minimumAgeSeconds): array
    {
        $sql = "
            SELECT resource.resource_id,
                   MIN(moderatorLog.log_date) AS approved_date
            FROM xf_rm_resource AS resource
            INNER JOIN xf_moderator_log AS moderatorLog
                    ON moderatorLog.content_type = ?
                   AND moderatorLog.content_id = resource.resource_id
                   AND moderatorLog.action = ?
            WHERE resource.resource_state = 'visible'
            GROUP BY resource.resource_id, resource.last_update, resource.resource_date
            HAVING approved_date > resource.resource_date
                   AND approved_date < resource.last_update
                   AND approved_date < ?
            ORDER BY approved_date ASC
            LIMIT " . (int) $limit
        ;

        $params = [
            PublishDateManager::CONTENT_TYPE_RESOURCE,
            'approve',
            time() - $minimumAgeSeconds,
        ];

        $candidates = [];
        foreach (\XF::db()->fetchAll($sql, $params) as $row) {
            $candidates[] = [
                'resource_id' => (int) $row['resource_id'],
                'approved_date' => (int) $row['approved_date'],
            ];
        }

        return $candidates;
    }

    /**
     * Re-dates the discussion thread belonging to a resource, when it is still
     * eligible for it.
     *
     * Note this uses applyThreadDate()/evaluateThreadStructure() rather than
     * bumpThread(): the thread is being re-dated because of a historical approval,
     * not because an approval is happening now.
     */
    private function processResourceThread(
        ResourceItem $resource,
        int $approvedDate,
        bool $dryRun,
        PublishDateManager $manager
    ): void {
        if (!$resource->discussion_thread_id) {
            return;
        }

        /** @var Thread|null $thread */
        $thread = \XF::em()->find(Thread::class, (int) $resource->discussion_thread_id);

        if (!$thread || $thread->discussion_state !== 'visible') {
            return;
        }

        if ($thread->discussion_type !== PublishDateManager::THREAD_TYPE_RESOURCE) {
            return;
        }

        if ($manager->evaluateThreadStructure($thread, $approvedDate) !== null) {
            return;
        }

        if (!$dryRun) {
            $manager->applyThreadDate($thread, $approvedDate);
        }
    }

    private function formatTimestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '-';
        }

        return \XF::app()->language()->date($timestamp, 'absolute');
    }

    private function truncateTitle(string $title): string
    {
        $title = Str::clean($title);

        return Str::strlen($title) > 40
            ? Str::substr($title, 0, 37) . '...'
            : $title;
    }
}
