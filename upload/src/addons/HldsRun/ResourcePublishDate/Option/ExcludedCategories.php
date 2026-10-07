<?php

namespace HldsRun\ResourcePublishDate\Option;

use XF\Entity\Option;
use XF\Option\AbstractOption;

/**
 * Renders the "excluded resource categories" option as a checkbox list built
 * from the live XFRM category tree.
 *
 * This is the same mechanism XenForo core uses for sitemap exclusions
 * (XF\Option\SitemapExclude): edit_format="callback" plus a validation class.
 * It is preferred over the older `option_template_*` approach because it needs
 * no template, therefore no template rebuild when XFRM is updated, and because
 * the choices are generated from the same repository method the Resource
 * Manager itself uses.
 */
class ExcludedCategories extends AbstractOption
{
    /**
     * @return string
     */
    public static function renderCheckbox(Option $option, array $htmlParams)
    {
        $choices = self::getCategoryChoices();

        $selected = $option->option_value;
        if (!is_array($selected)) {
            $selected = [];
        }

        return static::getCheckboxRow($option, $htmlParams, $choices, array_values($selected));
    }

    /**
     * Casts the submitted value into the canonical shape: a list of positive
     * integer category ids.
     *
     * @param mixed $optionValue
     */
    public static function verifyOption(&$optionValue, Option $option, string $optionId): bool
    {
        if ($option->isInsert()) {
            // On install the default value is trusted; there is nothing submitted.
            return true;
        }

        if (!is_array($optionValue)) {
            $optionValue = [];
        }

        $known = self::getKnownCategoryIds();

        $clean = [];
        foreach ($optionValue as $categoryId) {
            $categoryId = (int) $categoryId;
            if ($categoryId <= 0) {
                continue;
            }

            // Drop ids that no longer exist so the option cannot accumulate
            // stale entries after categories are deleted or merged.
            if ($known && !in_array($categoryId, $known, true)) {
                continue;
            }

            $clean[] = $categoryId;
        }

        $optionValue = array_values(array_unique($clean));

        return true;
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    private static function getCategoryChoices(): array
    {
        try {
            /** @var \XFRM\Repository\Category $categoryRepo */
            $categoryRepo = \XF::repository('XFRM:Category');

            return $categoryRepo->getCategoryOptionsData(false, true);
        } catch (\Throwable $e) {
            // XFRM missing or the tree could not be built. Returning an empty
            // list degrades the option to a no-op instead of breaking the whole
            // options page.
            return [];
        }
    }

    /**
     * @return list<int>
     */
    private static function getKnownCategoryIds(): array
    {
        return array_keys(self::getCategoryChoices());
    }
}
