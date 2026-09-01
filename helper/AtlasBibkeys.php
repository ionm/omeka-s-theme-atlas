<?php
namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;

/**
 * Citation tokens for essay pages.
 *
 * An essay is a site page, not an item, so it has no dcterms:references and no
 * resource links. Instead the prose carries the SAME bare Zotero citation keys
 * that Libra's `dc.relation.ord` carries and that the DspaceConnector fork
 * resolves with resolveCitationKey() - one key namespace across imported
 * records and hand-written prose.
 *
 *   {{guye1958}}              -> (Guye 1958)
 *   {{guye1958, 25}}          -> (Guye 1958, 25)          editorial convention
 *   {{guye1958; rih1959}}     -> (Guye 1958; Rih 1959)
 *   {{@kartaschoff1958}}      -> Kartaschoff (1958)        narrative
 *
 * Keys resolve against dcterms:identifier, exactly as resolveCitationKey() does
 * (atlas-docs/04 section 5, atlas-docs/05). An UNRESOLVED key is printed as its
 * bare text, deliberately: a stray "rentschbonanomi1964" in the middle of a
 * sentence is the same visible failure signal as a literal in the References
 * row of a clock page, and needs no separate report to be noticed.
 *
 * Rendering goes through the Bibliography module's `citation` helper with a
 * theme-supplied template (common/atlas/citation-inline), so the in-text form
 * and the bibliography come from ONE CSL style and inherit the fork's date and
 * name patches. No module file is touched.
 *
 * State lives on this helper for the length of the request. View helpers are
 * per-request singletons, so an "Atlas bibliography" block rendered after the
 * prose blocks can print exactly the works those blocks cited.
 *
 * @see themes/atlas/view/common/block-template/atlas-essay.phtml
 * @see themes/atlas/view/common/block-template/atlas-bibliography.phtml
 */
class AtlasBibkeys extends AbstractHelper
{
    /**
     * A token: {{ ... }} holding no braces and no angle brackets, so a stray
     * "{{" in prose can never swallow markup up to the next one.
     */
    const TOKEN = '~\{\{([^{}<>]{1,200}?)\}\}~';

    /** Zotero citation keys: letters, digits and the usual separators. */
    const KEY = '~^[A-Za-z0-9_.:-]+$~';

    const TEMPLATE_BIBLIOGRAPHY = 'atlas-bibliography';

    /** @var array key => ItemRepresentation|null (null = looked up, not found) */
    protected $resolved = [];

    /** @var array key => ItemRepresentation, in first-citation order */
    protected $cited = [];

    /** @var array item id => bare in-text citation, e.g. "Guye 1958" */
    protected $inlineCache = [];

    /** @var array item id => full bibliographic citation */
    protected $fullCache = [];

    public function __invoke(): self
    {
        return $this;
    }

    /**
     * Substitute every citation token in a block of essay HTML.
     */
    public function essay(string $html, ?SitePageBlockRepresentation $block = null): string
    {
        if (strpos($html, '{{') === false) {
            return $html;
        }

        $anchors = $this->hasBibliographyAfter($block);

        // Split on tags and substitute only in the text between them, so a
        // token can never be rewritten inside an attribute value.
        $parts = preg_split('~(<[^>]*>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($part === '' || $part[0] === '<') {
                continue;
            }
            $parts[$i] = preg_replace_callback(
                self::TOKEN,
                function ($m) use ($anchors) {
                    return $this->renderToken($m[1], $anchors);
                },
                $part
            );
        }

        return implode('', $parts);
    }

    /**
     * The works cited so far, in bibliography order.
     *
     * Sorted by the RENDERED citation, as References blocks on entry pages are
     * (see common/resource-values.phtml): chicago-author-date opens with the
     * first author's family name then the year, so the rendered string sorts as
     * a bibliography does - and follows the CSL style automatically if that
     * setting ever changes.
     *
     * @return array list of ['key' => string, 'item' => ItemRepresentation,
     *               'citation' => string]
     */
    public function citedWorks(): array
    {
        $works = [];
        foreach ($this->cited as $key => $item) {
            $citation = $this->fullCitation($item);
            if ($citation === '') {
                continue;
            }
            $works[] = [
                'key' => $key,
                'item' => $item,
                'citation' => $citation,
                'sort' => $this->sortKey($citation),
            ];
        }

        usort($works, function ($a, $b) {
            return strnatcasecmp($a['sort'], $b['sort']) ?: strnatcasecmp($a['key'], $b['key']);
        });

        return $works;
    }

    /** The anchor id a citation link points at. */
    public function anchorId(string $key): string
    {
        return 'ref-' . preg_replace('~[^A-Za-z0-9_.:-]~', '', $key);
    }

    /* --------------------------------------------------------------------- */

    /**
     * True when an "Atlas bibliography" block comes AFTER this one on the page.
     *
     * Only then may citations point at an in-page anchor: a bibliography block
     * placed before the prose cannot list works that have not been cited yet.
     * Without one, citations link to the reference entry instead - the page
     * always works, it just costs a navigation.
     */
    protected function hasBibliographyAfter(?SitePageBlockRepresentation $block): bool
    {
        if (!$block) {
            return false;
        }
        try {
            $blocks = $block->page()->blocks();
        } catch (\Throwable $e) {
            return false;
        }

        $passed = false;
        foreach ($blocks as $other) {
            if (!$passed) {
                $passed = $other->id() === $block->id();
                continue;
            }
            if ($other->layoutDataValue('template_name') === self::TEMPLATE_BIBLIOGRAPHY) {
                return true;
            }
        }

        return false;
    }

    /**
     * One token: "@key", "key, locator", "key; key2", or any combination.
     */
    protected function renderToken(string $raw, bool $anchors): string
    {
        $view = $this->getView();
        $escape = $view->plugin('escapeHtml');

        $raw = $original = trim($raw);
        $narrative = false;
        if (isset($raw[0]) && $raw[0] === '@') {
            $narrative = true;
            $raw = ltrim(substr($raw, 1));
        }

        $entries = [];
        foreach (explode(';', $raw) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $locator = null;
            if (($comma = strpos($chunk, ',')) !== false) {
                $locator = trim(substr($chunk, $comma + 1));
                $chunk = trim(substr($chunk, 0, $comma));
                $locator = $locator === '' ? null : $locator;
            }
            if (!preg_match(self::KEY, $chunk)) {
                // Not a citation token at all - a stray "{{" in prose, or a
                // template placeholder from somewhere else. Leave it alone.
                return '{{' . $original . '}}';
            }
            $entries[] = ['key' => $chunk, 'locator' => $locator];
        }

        if (!$entries) {
            return '{{' . $original . '}}';
        }

        $parts = [];
        foreach ($entries as $entry) {
            $key = $entry['key'];
            $item = $this->resolve($key);

            if (!$item) {
                // Unresolved: the bare key, as on clock pages.
                $parts[] = '<span class="atlas-citeref-unresolved">'
                    . $escape($key)
                    . ($entry['locator'] === null ? '' : ', ' . $escape($entry['locator']))
                    . '</span>';
                continue;
            }

            $this->cited[$key] = $item;

            $text = $this->inlineCitation($item);
            if ($text === '') {
                $parts[] = $escape($item->displayTitle());
                continue;
            }

            $href = $anchors
                ? '#' . $this->anchorId($key)
                : (string) $item->siteUrl();

            $label = $narrative ? $this->toNarrative($text) : $text;

            $rendered = sprintf(
                '<a class="atlas-citeref" href="%s">%s</a>',
                $escape($href),
                $label
            );
            if ($entry['locator'] !== null) {
                // Editorial convention: (Author year, page).
                $rendered .= ', ' . $escape($entry['locator']);
            }
            $parts[] = $rendered;
        }

        $joined = implode('; ', $parts);

        // A narrative citation carries its own parentheses around the year.
        return $narrative ? $joined : '(' . $joined . ')';
    }

    /**
     * "Guye 1958" -> "Guye (1958)". Falls back to the parenthetical form when
     * the citation does not end in a year, which no author-date style should
     * produce but an incomplete record can.
     */
    protected function toNarrative(string $text): string
    {
        if (preg_match('~^(.*\S)\s+((?:\d{4}|n\.d\.)[a-z]?)$~u', $text, $m)) {
            return $m[1] . ' (' . $m[2] . ')';
        }
        return '(' . $text . ')';
    }

    /**
     * The bare in-text citation for an item: "Guye 1958", parentheses stripped
     * so the caller can group several inside one pair.
     *
     * Each key is rendered on its own rather than handing citeproc the whole
     * group, because every key needs its own link - and chicago-author-date
     * joins a group with "; " anyway, which is what is rebuilt here.
     */
    protected function inlineCitation(ItemRepresentation $item): string
    {
        $id = $item->id();
        if (array_key_exists($id, $this->inlineCache)) {
            return $this->inlineCache[$id];
        }

        $text = '';
        $view = $this->getView();
        if ($view->getHelperPluginManager()->has('citation')) {
            $text = trim((string) $view->citation($item, [
                'template' => 'common/atlas/citation-inline',
                'bibliographic' => true,
                'tag' => '',
            ]));
        }

        // Strip the style's outer parentheses; keep any inside untouched.
        if (strlen($text) > 1 && $text[0] === '(' && substr($text, -1) === ')') {
            $text = trim(substr($text, 1, -1));
        }

        return $this->inlineCache[$id] = $text;
    }

    /** The full bibliographic citation, as reference pages render it. */
    protected function fullCitation(ItemRepresentation $item): string
    {
        $id = $item->id();
        if (array_key_exists($id, $this->fullCache)) {
            return $this->fullCache[$id];
        }

        $text = '';
        $view = $this->getView();
        if ($view->getHelperPluginManager()->has('citation')) {
            $text = trim((string) $view->citation($item, [
                'bibliographic' => true,
                'tag' => '',
            ]));
        }

        return $this->fullCache[$id] = $text;
    }

    /**
     * Resolve a bare citation key against dcterms:identifier - the same lookup
     * DspaceConnector\Job\Import::resolveCitationKey() performs, so a key that
     * works in Libra's dc.relation.ord works here and vice versa.
     */
    protected function resolve(string $key): ?ItemRepresentation
    {
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        try {
            $matches = $this->getView()->api()->search('items', [
                'property' => [[
                    'property' => 'dcterms:identifier',
                    'type' => 'eq',
                    'text' => $key,
                ]],
                'limit' => 1,
            ])->getContent();
        } catch (\Throwable $e) {
            $matches = [];
        }

        return $this->resolved[$key] = $matches ? $matches[0] : null;
    }

    /** Same normalisation as the References block on entry pages. */
    protected function sortKey(string $citation): string
    {
        $text = html_entity_decode(strip_tags($citation), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return (string) preg_replace('~^[\p{P}\p{Z}]+~u', '', trim($text));
    }
}
