# Atlas — an Omeka S theme

A standalone theme (no Foundation, no build step) built for **The Atomic Clock Atlas**, a catalogue
of scientific instruments and the documents and literature about them. It implements a print-derived
design system: warm paper, ink, a single oxblood accent; EB Garamond for running text with IBM Plex
Sans and IBM Plex Mono for interface and data; hairlines instead of boxes.

It is a working theme rather than a starter kit. It makes opinionated decisions about how a record
should read — a "specimen plate" beside a metadata table, sectioned metadata, citations rendered
inline, references ordered as a bibliography — and those decisions are driven by theme settings
rather than hard-coded, so they can be pointed at other vocabularies and resource templates.

## Requirements

- Omeka S ^4.2
- **Faceted Browse** — the browse experience (list and grid views, facet rendering) is built on it.
- Optional, each adding a feature rather than being required: **Bibliography** (CSL citations and
  the generated bibliography), **Numeric Data Types** (date-range facets), **Metadata Browse**.

## What is here

- `asset/css/atlas.css` — the entire stylesheet, design tokens at the top, print design in §10d.
- `asset/fonts/` — EB Garamond and IBM Plex, self-hosted, with their licences (see below).
- `view/common/atlas/` — the shared pieces: the specimen plate, entry card, citation and figure
  partials.
- `view/common/block-template/` — block templates: hero, entries, plate, bibliography, three figure
  widths, and the essay byline.
- `view/faceted-browse/`, `view/common/faceted-browse/` — browse results and facet rendering.
- `helper/` — three view helpers: facet links, citation-key resolution, essay detection and front
  matter.

## The masthead image

**No image ships with the theme.** Upload one under **Assets**, then select it in the theme's
settings as the masthead image. It is used in the masthead, in the hero block, and as the placeholder
for entries that have no image of their own. Leave it unset and those places simply render without an
image — nothing breaks.

## Settings

Configured under Sites → *your site* → Theme → Settings. Beyond the visual ones (tagline, masthead
image, navigation depth, footer line and licence, two partner logos), several settings tell the theme
about *your* data and are worth reading before use:

- which properties carry the lead paragraph, external links, video links and repository links;
- which resource templates get sectioned metadata, citations, or an eyebrow label;
- which property holds citations, and how they are sorted;
- the placeholder caption for entries without an image.

Every one of them defaults to something sensible for a catalogue of objects and documents, and none
of them assume this project's vocabulary.

## Fonts

EB Garamond and IBM Plex are bundled as subsetted `woff2` files. Both are licensed under the SIL Open
Font License 1.1, and both licences are included in `asset/fonts/`. They are not covered by this
theme's licence — the OFL travels with the font files, and must continue to.

## Licence

This theme is distributed under the **GNU General Public License v3**, the licence Omeka S and its
official themes use. See `LICENSE`.

Written for The Atomic Clock Atlas by [ionm](https://github.com/ionm).
