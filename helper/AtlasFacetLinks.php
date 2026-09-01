<?php
namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;

/**
 * Builds deep links into the site's Faceted Browse page: a URL whose fragment
 * carries the module's state JSON, so the catalogue opens with one facet value
 * preselected (the same mechanism as the module's "Copy permalink").
 *
 * Usage: $url = $this->AtlasFacetLinks()->url('aclocks:operatingprinciple', 'maser');
 * Returns null when the page, category or facet cannot be resolved.
 */
class AtlasFacetLinks extends AbstractHelper
{
    protected $loaded = false;

    /** @var ?string */
    protected $pageUrl;

    /** @var ?int */
    protected $categoryId;

    /** @var string */
    protected $joiner = 'and';

    /** @var array term => ['facet_id' => int, 'property_id' => int, 'query_type' => string] */
    protected $map = [];

    public function __invoke(): self
    {
        return $this;
    }

    public function url(string $term, string $value): ?string
    {
        $this->load();
        if (!$this->pageUrl || !isset($this->map[$term]) || $value === '') {
            return null;
        }
        $facet = $this->map[$term];
        $facetId = (string) $facet['facet_id'];
        $query = sprintf(
            'property[0][joiner]=%s&property[0][property]=%d&property[0][type]=%s',
            $this->joiner,
            $facet['property_id'],
            $facet['query_type']
        );
        if (!in_array($facet['query_type'], ['ex', 'nex'])) {
            $query .= '&property[0][text]=' . rawurlencode($value);
        }
        $state = [
            'categoryId' => $this->categoryId,
            'sortBy' => null,
            'sortOrder' => null,
            'page' => null,
            'facetStates' => [$facetId => [$value]],
            'facetQueries' => [$facetId => $query],
        ];
        return $this->pageUrl . '#' . rawurlencode(json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $view = $this->getView();
        try {
            $site = $view->currentSite();
            if (!$site) {
                return;
            }
            // The catalogue page: taken from the back-link target when it
            // points to a faceted-browse page, else the site's first one.
            $page = null;
            $backUrl = (string) $view->themeSetting('back_link_url', '');
            if (preg_match('~faceted-browse/(\d+)~', $backUrl, $m)) {
                $page = $view->api()->read('faceted_browse_pages', (int) $m[1])->getContent();
            } else {
                $pages = $view->api()->search('faceted_browse_pages', [
                    'site_id' => $site->id(),
                    'per_page' => 1,
                ])->getContent();
                $page = $pages ? $pages[0] : null;
            }
            if (!$page) {
                return;
            }
            $categories = $page->categories();
            if (!$categories) {
                return;
            }
            $category = reset($categories);
            $this->categoryId = $category->id();
            $this->joiner = ('or' === $category->valueFacetMode()) ? 'or' : 'and';
            $this->pageUrl = rtrim($site->url(), '/') . '/faceted-browse/' . $page->id();

            foreach ($category->facets() as $facet) {
                if ($facet->type() !== 'value') {
                    continue;
                }
                $propertyId = (int) $facet->data('property_id');
                if (!$propertyId) {
                    continue;
                }
                try {
                    $term = $view->api()->read('properties', $propertyId)->getContent()->term();
                } catch (\Throwable $e) {
                    continue;
                }
                // First facet per property wins.
                if (!isset($this->map[$term])) {
                    $this->map[$term] = [
                        'facet_id' => $facet->id(),
                        'property_id' => $propertyId,
                        'query_type' => $facet->data('query_type') ?: 'eq',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Module absent or page unreadable: links simply stay plain.
            $this->pageUrl = null;
        }
    }
}
