<?php

namespace HldsRun\ResourcePublishDate\Controller\Admin;

use HldsRun\ResourcePublishDate\Service\BackfillService;
use HldsRun\ResourcePublishDate\Service\PublishDateManager;
use XF\AdminController;

/**
 * Runs the backfill from Admin CP -> Options, in batches, without the CLI.
 *
 * ## Why there is a controller and no page
 *
 * The buttons on the options page link here, and every action redirects straight
 * back to wherever the administrator came from with a message. That is deliberate:
 * this add-on ships no templates and no JavaScript.
 *
 * XenForo's release builder does not put `_output/` in the add-on ZIP - its own
 * documentation says the directory "is not required for a successful installation
 * of an add-on, and shouldn't be included when releasing the add-on" - so a
 * template written under `_output/templates/` exists on the developer's machine
 * and nowhere else. There is no confirmation screen, no form and no button styling
 * of our own to build here; everything on the options page is rendered by
 * Option\BackfillTools through XenForo's own templater.
 *
 * @see \HldsRun\ResourcePublishDate\Option\BackfillTools
 * @see docs/DEVELOPING.md, "No templates, no JavaScript"
 */
class Backfill extends AdminController
{
    /**
     * Shows what one batch would do, and arms the run button.
     *
     * Nothing is written. This is the same state the CLI's --dry-run reports,
     * reduced to the number that fits in a message.
     */
    public function actionIndex()
    {
        $this->assertAdminPermission('option');

        /** @var BackfillService $backfill */
        $backfill = \XF::service(BackfillService::class);

        $result = $backfill->runBatch(
            $backfill->getBatchSize(),
            true,
            0,
            $backfill->shouldReDateThreads()
        );

        if ($result->isEmpty()) {
            $backfill->disarmConfirmation();

            return $this->redirect(
                $this->getContextualRedirect(),
                \XF::phrase('hlds_run_rpd_backfill_nothing'),
                'info'
            );
        }

        // Armed only for a batch that would actually do something: a preview of
        // zero candidates must not leave a run button behind.
        $backfill->armConfirmation($result->getMovedCount());

        return $this->redirect(
            $this->getContextualRedirect(),
            \XF::phrase('hlds_run_rpd_backfill_preview', ['count' => $result->getMovedCount()]),
            'info'
        );
    }

    /**
     * Writes one batch, for a confirmation that was issued by actionIndex().
     *
     * The confirmation key is the whole protection on this action, so the shape of
     * it matters: it is 32 hex characters from random_bytes(), it lives in this
     * administrator's session, and it is consumed whatever the outcome. A third
     * party cannot forge one - same-origin policy stops them reading it off the
     * page - and a second click of the same button does nothing. That is the
     * substitute for a CSRF token on a form, which this add-on cannot ship.
     *
     * It is a GET, and that is not ideal. It is the price of not shipping
     * JavaScript: there is no form to POST. The write is bounded by
     * hldsRunRpdBackfillBatchSize and idempotent - a resource already at its
     * approval date fails the structural check and is skipped - so a replayed
     * link cannot move anything twice.
     */
    public function actionRun()
    {
        $this->assertAdminPermission('option');

        /** @var BackfillService $backfill */
        $backfill = \XF::service(BackfillService::class);

        $key = (string) $this->getRequest()->getString('key');

        if (!$backfill->consumeConfirmation($key)) {
            return $this->redirect(
                $this->getContextualRedirect(),
                \XF::phrase('hlds_run_rpd_backfill_no_confirmation'),
                'error'
            );
        }

        /** @var PublishDateManager $manager */
        $manager = \XF::service(PublishDateManager::class);

        $result = $backfill->runBatch(
            $backfill->getBatchSize(),
            false,
            0,
            $backfill->shouldReDateThreads(),
            $manager->isLoggingEnabled()
        );

        // The query is ordered oldest approval first, so a board with more work
        // than one batch worth is finished by clicking again rather than by one
        // long request. Say so, instead of leaving the administrator to guess
        // whether it worked.
        //
        // Two literal \XF::phrase() calls rather than one with a title chosen at
        // runtime: tools/check.php matches the literal form, and a typo in a
        // variable would only be found on a live forum.
        $phraseParams = [
            'moved' => $result->getMovedCount(),
            'skipped' => $result->getSkippedCount(),
        ];

        if ($backfill->hasMoreCandidates()) {
            $message = \XF::phrase('hlds_run_rpd_backfill_run_more', $phraseParams);
        } else {
            $message = \XF::phrase('hlds_run_rpd_backfill_run_done', $phraseParams);
        }

        return $this->redirect($this->getContextualRedirect(), $message, 'success');
    }

    /**
     * Forgets a pending confirmation, so the run button disappears again.
     *
     * Writes nothing; the only reason to want this is an administrator who
     * previewed by accident and would rather not have a live button sitting on
     * the page.
     */
    public function actionDiscard()
    {
        $this->assertAdminPermission('option');

        /** @var BackfillService $backfill */
        $backfill = \XF::service(BackfillService::class);
        $backfill->disarmConfirmation();

        return $this->redirect(
            $this->getContextualRedirect(),
            \XF::phrase('hlds_run_rpd_backfill_discarded'),
            'info'
        );
    }
}
