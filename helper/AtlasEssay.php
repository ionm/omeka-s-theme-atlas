<?php
namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\SitePageRepresentation;

/**
 * Essay pages: front matter, and the small prose transform the essay block
 * needs when it is part of one.
 *
 * An essay is a SITE PAGE, not an item, so it carries no properties at all -
 * the same constraint that produced the {{bibkey}} tokens (see AtlasBibkeys).
 * The authors, their affiliations, the publication line and the PDF URL are
 * therefore authored in ONE HTML block whose template is "Atlas essay byline"
 * (atlas-essay-head), as plain "Label = Value" lines:
 *
 *   Marguerite Devaud = Laboratoire suisse de recherches horlogeres
 *   Henri Perret      = Musee international d'horlogerie
 *   Published         = 14 March 2025 - Revised 2 June 2025
 *   Date              = 2025-03-14        (optional, machine-readable)
 *   PDF               = files/essays/neuchatel-1958.pdf
 *
 * Same free-text idiom as the resource template's Groups field and the
 * "entry_sections" theme setting: the admin holds the content, the theme holds
 * the presentation. Two reserved labels (Published, PDF, plus the optional
 * Date); everything else is an author row, in the order written.
 *
 * The block template prints the table; view/omeka/site/page/show.phtml reads
 * the SAME parse for the page chrome (Download (PDF)), the citation panel and
 * the reference-manager head tags - which is why this lives in a helper and
 * not in the block template. Results are cached per page for the request.
 *
 * @see themes/atlas/view/common/block-template/atlas-essay-head.phtml
 * @see themes/atlas/view/omeka/site/page/show.phtml
 * @see themes/atlas/helper/AtlasBibkeys.php
 */
class AtlasEssay extends AbstractHelper
{
    /** Block template that carries the front matter. */
    const TEMPLATE_HEAD = 'atlas-essay-head';

    /** Block template holding essay prose. */
    const TEMPLATE_ESSAY = 'atlas-essay';

    /** The three figure block templates, one per width. */
    const TEMPLATES_FIGURE = ['atlas-figure-margin', 'atlas-figure-text', 'atlas-figure-page'];

    /** Reserved front-matter labels; anything else is an author. */
    const RESERVED = ['published', 'date', 'pdf'];

    /** @var array page id => parsed front matter */
    protected $frontMatter = [];

    /** @var array page id => bool */
    protected $isEssay = [];

    /** @var bool the opening small caps are set once per page, on the first essay block */
    protected $ledeDone = false;

    public function __invoke(): self
    {
        return $this;
    }

    /**
     * Is this page an essay?
     *
     * Determined by what it is made of, not by where it sits in the navigation
     * or by a naming convention: a page carrying essay prose, essay front
     * matter or an essay figure IS an essay. Nothing to configure, and a page
     * moved in the navigation keeps its treatment.
     */
    public function isEssay(SitePageRepresentation $page): bool
    {
        $id = $page->id();
        if (array_key_exists($id, $this->isEssay)) {
            return $this->isEssay[$id];
        }
        $found = false;
        foreach ($page->blocks() as $block) {
            $template = (string) $block->layoutDataValue('template_name');
            if ($template === self::TEMPLATE_ESSAY
                || $template === self::TEMPLATE_HEAD
                || in_array($template, self::TEMPLATES_FIGURE, true)
            ) {
                $found = true;
                break;
            }
        }
        return $this->isEssay[$id] = $found;
    }

    /**
     * The page's front matter.
     *
     * @return array {
     *     @var array  $authors   list of ['name' => string, 'affiliation' => string]
     *     @var string $names     "A and B" / "A, B, and C", for the citation panel
     *     @var string $published the display line, printed as written
     *     @var string $date      ISO date for citation_publication_date, or ''
     *     @var string $pdf       PDF URL as written, or ''
     * }
     */
    public function frontMatter(SitePageRepresentation $page): array
    {
        $id = $page->id();
        if (array_key_exists($id, $this->frontMatter)) {
            return $this->frontMatter[$id];
        }
        $raw = '';
        foreach ($page->blocks() as $block) {
            if (self::TEMPLATE_HEAD === (string) $block->layoutDataValue('template_name')) {
                $raw = (string) $block->dataValue('html', '');
                break;
            }
        }
        return $this->frontMatter[$id] = $this->parse($raw);
    }

    /**
     * Parse "Label = Value" lines out of a block of rich-text HTML.
     *
     * The admin's editor wraps each line in <p> or separates them with <br>,
     * and turns a run of spaces into &nbsp; - so the tags become newlines, the
     * entities are decoded and the whitespace normalised before anything is
     * split. A line with no "=" is an author with no affiliation.
     */
    public function parse(string $html): array
    {
        $front = ['authors' => [], 'names' => '', 'published' => '', 'date' => '', 'pdf' => ''];

        $text = preg_replace('~<\s*(br|/p|/div|/li|/h[1-6])\b[^>]*>~i', "\n", $html);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);

        foreach (preg_split('~\R~u', $text) as $line) {
            $line = trim(preg_replace('~[ \t]+~u', ' ', $line));
            if ($line === '') {
                continue;
            }
            $label = $line;
            $value = '';
            if (false !== ($pos = strpos($line, '='))) {
                $label = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
            }
            if ($label === '') {
                continue;
            }
            $key = strtolower($label);
            if (in_array($key, self::RESERVED, true)) {
                $front[$key === 'date' ? 'date' : $key] = $value;
                continue;
            }
            $front['authors'][] = ['name' => $label, 'affiliation' => $value];
        }

        $front['names'] = $this->joinNames(array_column($front['authors'], 'name'));
        if ($front['date'] === '' && $front['published'] !== '') {
            $front['date'] = $this->isoDate($front['published']);
        }

        return $front;
    }

    /**
     * The essay's PDF, or '' when it has none.
     *
     * A "PDF =" line in the front matter wins, so an essay can point anywhere.
     * Otherwise the generated file is used IF IT EXISTS - files/essays/<slug>.pdf,
     * written by atlas-docs/scripts/essay-pdf.sh. So "Download (PDF)" and the
     * citation_pdf_url tag appear the moment an essay has been rendered and
     * disappear if the file is removed; nothing has to be declared twice, and an
     * essay that has never been rendered simply shows no link.
     */
    public function pdfUrl(SitePageRepresentation $page): string
    {
        $pdf = $this->frontMatter($page)['pdf'];
        if ($pdf === '') {
            $file = 'files/essays/' . $page->slug() . '.pdf';
            if (defined('OMEKA_PATH') && is_readable(OMEKA_PATH . '/' . $file)) {
                return $this->getView()->basePath($file);
            }
            return '';
        }
        if (preg_match('~^(https?:)?//~i', $pdf) || $pdf[0] === '/') {
            return $pdf;
        }
        // Relative to the INSTALLATION, not to the site: what an author writes
        // is a path like "files/essays/x.pdf", and Omeka's files live at
        // <base>/files, not under the site's own /s/<slug> prefix.
        return $this->getView()->basePath($pdf);
    }

    /**
     * Set the opening words of an essay in small caps.
     *
     * Only the FIRST paragraph of the FIRST essay block on the page, and only
     * when the author has not marked a lede span themselves - writing
     * <span class="atlas-essay-lede">In May 1957</span> in the block turns the
     * automatic version off for that essay, which is the escape hatch for an
     * opening whose first three words are the wrong three.
     */
    public function lede(string $html): string
    {
        if ($this->ledeDone || strpos($html, 'atlas-essay-lede') !== false) {
            $this->ledeDone = true;
            return $html;
        }
        $done = false;
        $out = preg_replace_callback(
            '~(<p\b[^>]*>\s*)([^<]+)~i',
            function ($m) use (&$done) {
                if ($done) {
                    return $m[0];
                }
                // Three words, keeping the whitespace that follows them.
                if (!preg_match('~^((?:\S+\s+){2}\S+)(\s*)(.*)$~su', $m[2], $w)) {
                    return $m[0];
                }
                $done = true;
                return $m[1] . '<span class="atlas-essay-lede">' . $w[1] . '</span>' . $w[2] . $w[3];
            },
            $html,
            1
        );
        $this->ledeDone = true;
        return $out === null ? $html : $out;
    }

    /* --------------------------------------------------------------------- */

    /** Chicago's author list: "A", "A and B", "A, B, and C". */
    protected function joinNames(array $names): string
    {
        $names = array_values(array_filter(array_map('trim', $names), 'strlen'));
        $count = count($names);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $names[0];
        }
        $translate = $this->getView()->plugin('translate');
        $and = $translate('and');
        if ($count === 2) {
            return $names[0] . ' ' . $and . ' ' . $names[1];
        }
        $last = array_pop($names);
        return implode(', ', $names) . ', ' . $and . ' ' . $last;
    }

    /**
     * "14 March 2025 - Revised 2 June 2025" -> "2025-03-14".
     *
     * The publication line is display text, so only the part before the first
     * separator is offered to the date parser, and an unparseable line simply
     * produces no citation_publication_date tag rather than a wrong one.
     */
    protected function isoDate(string $published): string
    {
        $head = trim(preg_split('~[\x{00B7}|;\x{2013}\x{2014}]~u', $published)[0]);
        if ($head === '') {
            return '';
        }
        try {
            $date = new \DateTime($head);
        } catch (\Exception $e) {
            return '';
        }
        return $date->format('Y-m-d');
    }
}
