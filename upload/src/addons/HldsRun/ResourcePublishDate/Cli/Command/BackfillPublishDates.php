<?php

namespace HldsRun\ResourcePublishDate\Cli\Command;

use HldsRun\ResourcePublishDate\Service\BackfillService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;
use XF\Util\Str;

/**
 * Re-dates resources that were approved before this add-on existed.
 *
 * A mass write to content ordering, therefore:
 *   - --dry-run is available, and it is what you should always run first;
 *   - moderator log entries are off by default here, because
 *     XF\ModeratorLog\AbstractHandler::setupLogEntityActor() needs an HTTP request
 *     to resolve the actor's IP address and throws without one;
 *   - the query is bounded by --limit and ordered oldest-approval-first, so a
 *     re-run always progresses through the oldest work instead of repeating it.
 *
 * The work itself is in Service\BackfillService, which the buttons in
 * Admin CP -> Options use too. What this class adds is the argument parsing and
 * the per-resource output; there is deliberately no logic below that line.
 *
 * @see Service\BackfillService
 * @see docs/TROUBLESHOOTING.md, "Recovering from a mistake"
 */
class BackfillPublishDates extends AbstractCommand
{
    private const DEFAULT_LIMIT = 500;

    protected function configure()
    {
        $this
            ->setName('hlds-run-rpd:backfill')
            // A literal, not \XF::phrase(): configure() runs while Runner is still
            // building the command list, before any XF app exists. Calling
            // \XF::phrase() here creates an XF\App implicitly, and the Runner then
            // fails to set up its own XF\Cli\App - "A second app cannot be setup" -
            // which takes down every CLI command on the board, not just this one.
            // Core's own commands describe themselves in English for the same
            // reason, and so does every XenForo add-on that ships a working CLI.
            //
            // The hlds_run_rpd_cli_*_description phrases stay in _data/phrases.xml
            // and in ru.xml: deleting a shipped phrase is a data change, and
            // something may still want to render that text. Nothing calls them
            // any more, which is why no check complains about their presence.
            ->setDescription('Moves the publish date of already-approved resources to the moment they were approved, using the moderator log.')
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

        /** @var BackfillService $backfill */
        $backfill = \XF::service(BackfillService::class);

        $result = $backfill->runBatch(
            (int) $input->getOption('limit'),
            $dryRun,
            max(0, (int) $input->getOption('days')) * 86400,
            (bool) $input->getOption('threads'),
            (bool) $input->getOption('log')
        );

        if ($result->isEmpty()) {
            $output->writeln(\XF::phrase('hlds_run_rpd_cli_backfill_nothing'));

            return 0;
        }

        foreach ($result->getEntries() as $entry) {
            $output->writeln(sprintf(
                '  #%d %s: %s -> %s',
                $entry['resource_id'],
                self::truncateTitle($entry['title']),
                self::formatTimestamp($entry['from']),
                self::formatTimestamp($entry['to'])
            ));
        }

        $output->writeln('');

        if ($dryRun) {
            $output->writeln(
                \XF::phrase('hlds_run_rpd_cli_backfill_candidates', ['count' => $result->getMovedCount()])
            );
            $output->writeln('<comment>'
                . \XF::phrase('hlds_run_rpd_cli_backfill_dry_run')
                . '</comment>');

            return 0;
        }

        $output->writeln(
            \XF::phrase('hlds_run_rpd_cli_backfill_summary', [
                'moved' => $result->getMovedCount(),
                'skipped' => $result->getSkippedCount(),
            ])
        );

        return 0;
    }

    private static function formatTimestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '-';
        }

        return \XF::app()->language()->date($timestamp, 'absolute');
    }

    private static function truncateTitle(string $title): string
    {
        $title = Str::clean($title);

        return Str::strlen($title) > 40
            ? Str::substr($title, 0, 37) . '...'
            : $title;
    }
}
