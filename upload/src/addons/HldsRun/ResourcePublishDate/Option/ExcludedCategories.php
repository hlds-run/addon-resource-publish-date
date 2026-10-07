<?php

namespace HldsRun\ResourcePublishDate\Option;

use XF\Entity\Option;
use XF\Option\AbstractOption;

/**
 * Renders the "excluded resource categories" option as a compact multiple
 * select built from the live XFRM category tree - the same component the core
 * widget settings use for their "limit to nodes" field
 * (widget_def_options_new_posts and friends).
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
     * Rows shown before the select starts scrolling. Same as core's widget node
     * pickers: enough to show the shape of the tree without eating the page.
     */
    private const SELECT_SIZE = 7;

    /**
     * Sentinel for "exclude nothing". Deliberately the empty string rather
     * than 0, because that is what core's node pickers post and because
     * `(int) '' === 0`, which verifyOption() already discards.
     */
    private const ALL_CATEGORIES = '';

    /**
     * @return string
     */
    public static function renderMultiSelect(Option $option, array $htmlParams)
    {
        $choices = [self::ALL_CATEGORIES => [
            'value' => self::ALL_CATEGORIES,
            'label' => \XF::phrase('hlds_run_rpd_all_categories')->render(),
        ]];

        foreach (self::getCategoryChoices() as $categoryId => $choice) {
            $choices[$categoryId] = $choice;
        }

        $selected = $option->option_value;
        if (!is_array($selected)) {
            $selected = [];
        }
        $selected = array_map('intval', array_values($selected));

        // Preselect "All categories" rather than leaving every row unselected,
        // which reads as "I did not look at this field".
        if (!$selected) {
            $selected = [self::ALL_CATEGORIES];
        }

        // Built by hand rather than through static::getControlOptions(): that
        // helper reads $htmlParams['inputType'], which the option_macros
        // callback contract does not pass, and it also has no notion of a
        // multi-select.
        $controlOptions = [
            'name' => $htmlParams['inputName'],
            'value' => $selected,
            'multiple' => true,
            'size' => self::SELECT_SIZE,
        ];
        $rowOptions = static::getRowOptions($option, $htmlParams);

        // Deliberately not static::getSelectRow(): like getCheckboxRow() it
        // funnels the choices through Templater::mergeChoiceOptions(), which
        // only accepts scalar labels (`id => 'Title'`). getCategoryOptionsData()
        // returns the structured form (`id => ['value' => id, 'label' => …]`),
        // every entry of which mergeChoiceOptions() silently discards - leaving
        // an empty control, i.e. a settings row with a title and an explanation
        // but no way to answer the question.
        return \XF::app()->templater()->formSelectRow($controlOptions, $choices, $rowOptions);
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
     * Flattens the live XFRM category tree into select choices, indented by
     * depth.
     *
     * Not getCategoryOptionsData(): that bakes the depth into the label as a
     * run of hyphens (`str_repeat('--', $depth)`), because its consumers are
     * mostly plain-text dropdowns. A hyphen is a character, so it survives
     * anywhere the title can wrap, and it reads as content rather than as
     * structure. Templater::formSelect() already knows how to keep `&nbsp;`
     * entities intact through its escape pass, so the indent is built here
     * instead - the same trick core's widget node pickers use.
     *
     * @return array<int, array{value: int, label: string}>
     */
    private static function getCategoryChoices(): array
    {
        try {
            /** @var \XFRM\Repository\Category $categoryRepo */
            $categoryRepo = \XF::repository('XFRM:Category');

            $categoryList = $categoryRepo->findCategoryList()->fetch();
            $categoryList = $categoryList->filterViewable();

            $choices = [];
            $tree = $categoryRepo->createCategoryTree($categoryList)->getFlattened();

            foreach ($tree as $entry) {
                /** @var \XFRM\Entity\Category $category */
                $category = $entry['record'];

                $choices[$category->getEntityId()] = [
                    'value' => $category->getEntityId(),
                    'label' => str_repeat('&nbsp;&nbsp;', $entry['depth'])
                        . ($entry['depth'] ? '&nbsp;' : '')
                        . $category->title,
                ];
            }

            return $choices;
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
