<?php

namespace HldsRun\ResourcePublishDate\Service;

use XF\App;
use XF\Entity\Thread;
use XF\Service\AbstractService;
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
 *
 *   - the dry run is a first-class mode, not an afterthought, and both callers
 *     are expected to expose it;
 *   - the query is bounded by a limit and ordered oldest-approval-first, so a
 *     re-run always progresses through the oldest work instead of repeating it;
 *   - the batch is bounded by hldsRunRpdBackfillBatchSize, because the Admin CP
 *     runs this inside an ordinary web request and an unbounded run would be
 *     killed by max_execution_time halfway through.
 *
 * Two callers, one implementation:
 *
 *   - hlds-run-rpd:backfill, where the caller states every parameter;
 *   - the buttons in Admin CP -> Options, where the caller passes the batch size
 *     and nothing else and the rest comes from the options.
 *
 * The two deliberately disagree about threads. The command needs an explicit
 * --threads because a CLI run is unattended and re-dating a thread is a second
 * write per resource; in the Admin CP the administrator is looking at the option
 * that says whether threads should be re-dated at all, so the button follows that
 * option instead of asking again.
 *
 * Only resources whose stored publish date is NEWER than their approval date are
 * considered, and resources in excluded categories are never touched.
 *
 * @see docs/TROUBLESHOOTING.md, "Recovering from a mistake"
 * @see docs/BEHAVIOR.md
 */
class BackfillService extends AbstractService
{
    /**
     * Upper bound for the Admin CP batch size option.
     *
     * The CLI will happily process more, because it has no request to be killed
     * in the middle of; a web request does.
     */
    public const MAX_BATCH_SIZE = 500;

    /**
     * Batch size used when hldsRunRpdBackfillBatchSize is unset or nonsense.
     *
     * Low on purpose: one that is too small costs the administrator one more
     * click, one that is too large times out mid-write.
     */
    private const FALLBACK_BATCH_SIZE = 100;

    /**
     * Session key holding the pending confirmation for an Admin CP batch.
     *
     * Per administrator, not per board: two staff members previewing at the same
     * time must not invalidate each other, and neither must be able to run the
     * other's preview.
     */
    private const CONFIRMATION_SESSION_KEY = 'hldsRunRpdBackfillConfirmation';

    public function __construct(App $app)
    {
        parent::__construct($app);
    }

    /**
     * How many resources one Admin CP click may touch.
     */
    public function getBatchSize(): int
    {
        $batchSize = (int) $this->app->options()->hldsRunRpdBackfillBatchSize;

        if ($batchSize < 1) {
            return self::FALLBACK_BATCH_SIZE;
        }

        return min($batchSize, self::MAX_BATCH_SIZE);
    }

    /**
     * Whether a discussion thread is re-dated along with its resource.
     *
     * Reads the same option the approval flow reads, so an administrator who has
     * turned thread re-dating off does not get it from the backfill either.
     */
    public function shouldReDateThreads(): bool
    {
        /** @var PublishDateManager $manager */
        $manager = $this->service(PublishDateManager::class);

        return $manager->shouldBumpThreads();
    }

    /**
     * The session this administrator is browsing in.
     *
     * \XF\App::session() rather than a $this->app() helper, because
     * AbstractService has no session() of its own and class_check.php would
     * rightly refuse to let a $this->session() call through.
     *
     * @return \XF\Session
     */
    private function getSession()
    {
        return \XF::app()->session();
    }

    /**
     * Runs one batch.
     *
     * @param bool     $dryRun          evaluate without writing
     * @param int      $limit           maximum resources to process
     * @param int      $minimumAgeSeconds only consider approvals older than this;
     *                                    0 disables the filter
     * @param bool     $includeThreads  also re-date the discussion thread of each
     *                                    resource, where it is still eligible
     * @param bool     $log             write a moderator log entry per resource
     */
    public function runBatch(
        int $limit,
        bool $dryRun,
        int $minimumAgeSeconds = 0,
        bool $includeThreads = false,
        bool $log = false
    ): BackfillResult {
        $limit = max(1, $limit);

        /** @var PublishDateManager $manager */
        $manager = $this->service(PublishDateManager::class);

        // The caller states its intent rather than this method deciding: the CLI
        // command passes false by default, because it has no HTTP request to
        // resolve the moderator's IP address from and the core handler throws,
        // while the Admin CP passes whatever hldsRunRpdLogToModeratorLog says
        // because there is a request. A dry run is never logged either way,
        // since nothing is written for a log entry to point at.
        $manager->setLoggingEnabled(!$dryRun && $log);

        $candidates = $this->findResourceCandidates($limit, $minimumAgeSeconds);

        $entries = [];
        $skipped = 0;

        foreach ($candidates as $candidate) {
            $resourceId = (int) $candidate['resource_id'];
            $approvedDate = (int) $candidate['approved_date'];

            /** @var ResourceItem|null $resource */
            $resource = $this->em()->find(ResourceItem::class, $resourceId);
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

            if ($manager->evaluateResourceStructure($resource, $approvedDate) !== null) {
                ++$skipped;
                continue;
            }

            $previousDate = (int) $resource->last_update;

            if (!$dryRun) {
                $result = $manager->applyResourceDate($resource, $approvedDate);

                if (!$result->isBumped()) {
                    ++$skipped;
                    continue;
                }
            }

            $entries[] = [
                'resource_id' => $resourceId,
                'title' => (string) $resource->title,
                'from' => $previousDate,
                'to' => $approvedDate,
            ];

            if ($includeThreads) {
                $this->processResourceThread($resource, $approvedDate, $dryRun, $manager);
            }
        }

        return new BackfillResult($dryRun, $entries, $skipped);
    }

    /**
     * Whether another batch would find anything after the one that just ran.
     *
     * A single-row probe rather than a COUNT(*): the count would scan every
     * candidate instead of stopping at the first, and the only question this
     * answers is "is the queue empty yet".
     */
    public function hasMoreCandidates(int $minimumAgeSeconds = 0): bool
    {
        return (bool) $this->findResourceCandidates(1, $minimumAgeSeconds);
    }

    // ------------------------------------------------------------------
    // Admin CP confirmation
    //
    // A preview, a key, and a run that will only happen once. See
    // Controller\Admin\Backfill for why this exists at all rather than a form.
    // ------------------------------------------------------------------

    /**
     * Records that a batch has been previewed and may now be run.
     */
    public function armConfirmation(int $candidateCount): void
    {
        $this->getSession()->set(self::CONFIRMATION_SESSION_KEY, [
            'key' => bin2hex(random_bytes(16)),
            'count' => $candidateCount,
        ]);
    }

    /**
     * The key a pending run has to present, or null when nothing is pending.
     *
     * Read by the options row to decide whether to draw the run button at all.
     */
    public function getPendingConfirmation(): ?string
    {
        $state = $this->getSession()->get(self::CONFIRMATION_SESSION_KEY);

        if (!is_array($state) || empty($state['key']) || !is_string($state['key'])) {
            return null;
        }

        return $state['key'];
    }

    /**
     * How many resources the pending batch would move, for display.
     */
    public function getPendingCount(): int
    {
        $state = $this->getSession()->get(self::CONFIRMATION_SESSION_KEY);

        return is_array($state) && isset($state['count']) ? (int) $state['count'] : 0;
    }

    /**
     * Accepts $key exactly once.
     *
     * The stored value is deleted before the comparison and regardless of its
     * result, so a wrong key cannot be brute-forced by repeated requests and a
     * correct one cannot be replayed.
     */
    public function consumeConfirmation(string $key): bool
    {
        $pending = $this->getPendingConfirmation();
        $this->disarmConfirmation();

        if ($pending === null || $key === '') {
            return false;
        }

        return hash_equals($pending, $key);
    }

    public function disarmConfirmation(): void
    {
        $this->getSession()->delete(self::CONFIRMATION_SESSION_KEY);
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
        foreach ($this->db()->fetchAll($sql, $params) as $row) {
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
        $thread = $this->em()->find(Thread::class, (int) $resource->discussion_thread_id);

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
}
