<?php

namespace HldsRun\ResourcePublishDate\Option;

use HldsRun\ResourcePublishDate\Service\BackfillService;
use XF\Entity\Option;
use XF\Option\AbstractOption;

/**
 * Renders the two backfill buttons in Admin CP -> Options.
 *
 * One place for them on purpose. A backfill is an occasional, deliberate act, and
 * the administrator who needs it is standing in front of this option group anyway;
 * a second copy on an add-on navigation entry would be a second thing to document,
 * to keep in sync and to explain.
 *
 * ## The markup is hand-written, and that is not a shortcut
 *
 * XenForo will not put `_output/` into an add-on release ZIP - its own
 * documentation says the directory "is not required for a successful installation
 * of an add-on, and shouldn't be included when releasing the add-on" - so there is
 * no JS file to style these with and no template to put them in. The same
 * edit_format="callback" mechanism as Option\ExcludedCategories renders this row;
 * only the string returned at the end differs.
 *
 * Which is why there is no Templater::formButtonRow() here: that helper renders
 * <input type="submit"> controls for a form, and these are links into a controller
 * that writes on GET. See Controller\Admin\Backfill for how the write is protected
 * instead.
 *
 * @see \HldsRun\ResourcePublishDate\Controller\Admin\Backfill
 * @see docs/DEVELOPING.md, "No templates, no JavaScript"
 */
class BackfillTools extends AbstractOption
{
    /**
     * Route name, as registered in Setup::preRouteBuild().
     */
    private const ROUTE = 'hldsRunRpdBackfill';

    /**
     * @return string
     */
    public static function renderTools(Option $option, array $htmlParams)
    {
        /** @var BackfillService $backfill */
        $backfill = \XF::service(BackfillService::class);

        $buttons = [
            self::link(
                self::ROUTE,
                [],
                \XF::phrase('hlds_run_rpd_backfill_preview_button')->render()
            )
        ];

        $key = $backfill->getPendingConfirmation();
        $notice = '';

        if ($key !== null) {
            // The run button is not rendered at all until a preview exists, so
            // the destructive action is always preceded by one. The count is the
            // size of the batch that was previewed, not of the whole board.
            $notice = '<p>' . \XF::phrase('hlds_run_rpd_backfill_pending', [
                'count' => $backfill->getPendingCount(),
            ])->render() . '</p>';

            $buttons[] = self::link(
                self::ROUTE,
                ['action' => 'run', 'key' => $key],
                \XF::phrase('hlds_run_rpd_backfill_run_button')->render()
            );
            $buttons[] = self::link(
                self::ROUTE,
                ['action' => 'discard'],
                \XF::phrase('hlds_run_rpd_backfill_discard_button')->render()
            );
        }

        return $notice
            . '<div class="buttonGroup">'
            . implode('', $buttons)
            . '</div>';
    }

    /**
     * Forces the option's value back to the empty string.
     *
     * This row has no input, so there is nothing for an administrator to set and
     * nothing for the database to remember. Normalising on every save is what
     * keeps it that way if anything ever posts a value here.
     *
     * @param mixed $optionValue
     */
    public static function verifyOption(&$optionValue, Option $option, string $optionId): bool
    {
        $optionValue = '';

        return true;
    }

    /**
     * A styled link to one of our own actions.
     *
     * The URL is escaped and the label is not, because a XenForo phrase is trusted
     * HTML by design - that is how every core template renders one, and a
     * translator is allowed to put markup in it. The URL is ours, and a link
     * attribute is not the place to be relaxed about that.
     *
     * @param array<string, mixed> $params
     */
    private static function link(string $route, array $params, string $label): string
    {
        $url = \XF::app()->router()->buildLink($route, $params);

        return '<a class="button" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . $label
            . '</a>';
    }
}
