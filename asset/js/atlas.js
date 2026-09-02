(function () {
    'use strict';

    /**
     * Catalogue list/grid view (1.9.1).
     *
     * The view is a query parameter on the browse requests (?view=grid; list
     * is the default). Sort and pagination reproduce the current query on
     * their own, but Faceted Browse rebuilds the browse query from scratch on
     * every facet change and on first load, so the current view is re-appended
     * to any browse request that does not already carry one. An explicit URL
     * parameter wins; otherwise the visitor's last choice (localStorage);
     * otherwise the list. Read at execution time: the module's initState()
     * strips the query string from the address bar when a permalink or tag
     * link arrives with a state fragment.
     */
    var VIEW_KEY = 'atlas:browse-view';
    var currentView = (function () {
        var m = /[?&]view=([^&#]*)/.exec(window.location.search);
        if (m) {
            return 'grid' === decodeURIComponent(m[1]) ? 'grid' : 'list';
        }
        try {
            var stored = window.localStorage.getItem(VIEW_KEY);
            if ('grid' === stored || 'list' === stored) {
                return stored;
            }
        } catch (e) {}
        return 'list';
    })();

    function setView(view) {
        currentView = view;
        try { window.localStorage.setItem(VIEW_KEY, view); } catch (e) {}
        // Reflect the view in the address bar so the page stays shareable.
        try {
            var url = window.location.pathname;
            var params = window.location.search.replace(/^\?/, '').split('&').filter(function (p) {
                return p && 0 !== p.indexOf('view=');
            });
            if ('grid' === view) {
                params.push('view=grid');
            }
            if (params.length) {
                url += '?' + params.join('&');
            }
            window.history.replaceState(window.history.state, '', url + window.location.hash);
        } catch (e) {}
    }

    // Registered at script execution, before the module's ready handler can
    // fire the first browse request.
    if (window.jQuery) {
        window.jQuery.ajaxPrefilter(function (options) {
            var container = document.getElementById('container');
            if (!container || !container.classList.contains('atlas-catalogue')) {
                return;
            }
            var urlBrowse = container.getAttribute('data-url-browse') || '';
            var url = options.url || '';
            if (!urlBrowse || url.split('?')[0] !== urlBrowse.split('?')[0]) {
                return;
            }
            var m = /[?&]view=([^&#]*)/.exec(url);
            if (m) {
                // A request that names a view (a toggle fetch, a sort or
                // pagination request reproducing the query) is the truth.
                setView('grid' === decodeURIComponent(m[1]) ? 'grid' : 'list');
            } else if ('grid' === currentView) {
                options.url = url + (-1 === url.indexOf('?') ? '?' : '&') + 'view=grid';
            }
        });
    }

    /**
     * Toggle clicks: swap the results for the same query in the other view.
     * data-browse-url carries the complete current browse query (facets, sort,
     * page) with only the view swapped, so nothing else changes. Modified
     * clicks fall through to the href (the page URL with the view parameter).
     */
    function catalogueViewToggle(container) {
        container.addEventListener('click', function (event) {
            var link = event.target.closest ? event.target.closest('.atlas-view-toggle a') : null;
            if (!link || event.defaultPrevented || event.button !== 0
                || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            event.preventDefault();
            setView('grid' === link.getAttribute('data-view') ? 'grid' : 'list');
            var url = link.getAttribute('data-browse-url');
            var content = document.getElementById('section-content');
            if (url && window.jQuery && content) {
                window.jQuery.get(url, function (html) {
                    window.jQuery(content).html(html);
                });
            } else {
                window.location.href = link.getAttribute('href');
            }
        });
    }

    function toggle(buttonId, targetId, onOpen) {
        var button = document.getElementById(buttonId);
        var target = document.getElementById(targetId);
        if (!button || !target) {
            return;
        }
        button.addEventListener('click', function () {
            var open = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', open ? 'false' : 'true');
            target.classList.toggle('is-open', !open);
            if (!open && onOpen) {
                onOpen(target);
            }
        });
    }

    /**
     * Faceted Browse loads its filter rail over AJAX, and its full-text facet
     * is built in PHP (no overridable partial), so the placeholder and the
     * inline width are applied here whenever the rail is (re)rendered.
     */
    function decorateFacets(container) {
        var placeholder = container.getAttribute('data-atlas-search-placeholder') || '';
        var sidebar = document.getElementById('section-sidebar');
        if (!sidebar) {
            return;
        }
        var apply = function () {
            var inputs = sidebar.querySelectorAll('input.full-text');
            for (var i = 0; i < inputs.length; i++) {
                if (placeholder && !inputs[i].getAttribute('placeholder')) {
                    inputs[i].setAttribute('placeholder', placeholder);
                }
                inputs[i].style.width = '';
            }
        };
        apply();
        new MutationObserver(apply).observe(sidebar, {childList: true, subtree: true});

        // The module opens the filter rail as a full-screen panel on narrow
        // screens; make sure it starts at the top of its own scroll box.
        var filtersButton = document.getElementById('section-sidebar-modal-toggle');
        if (filtersButton) {
            filtersButton.addEventListener('click', function () {
                window.requestAnimationFrame(function () {
                    sidebar.scrollTop = 0;
                });
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Narrow screens: the menu is collapsed by CSS until .is-open is set.
        toggle('atlas-nav-toggle', 'atlas-nav');
        // Search form is collapsed on every screen until toggled.
        toggle('atlas-search-toggle', 'atlas-search', function (target) {
            var input = target.querySelector('input[type="text"]');
            if (input) {
                input.focus();
            }
        });

        var catalogue = document.querySelector('.atlas-catalogue');
        if (catalogue) {
            decorateFacets(catalogue);
            catalogueTagLinks(catalogue);
            catalogueViewToggle(catalogue);
            // Put the resolved view in the address bar up front (a remembered
            // grid on a bare URL). Deferred a tick: the module's initState()
            // runs in a jQuery ready callback registered after this listener,
            // and it replaces the URL with the bare pathname when a permalink
            // or tag link arrives with a state fragment - the timeout puts
            // this replaceState after that one.
            window.setTimeout(function () { setView(currentView); }, 0);
        }

        entryPage();
        essayFigures();
    });

    /**
     * Facet tags in the catalogue's own result rows.
     *
     * A tag link is a deep link into this same page carrying the facet state
     * in the fragment (see helper/AtlasFacetLinks.php). Faceted Browse reads
     * that fragment on load only, so following the link from a result row -
     * same document, different hash - updates the address bar and nothing
     * else. Force the reload the module is waiting for. Scoped to our own tag
     * links, so the module's own facet controls are untouched.
     */
    function catalogueTagLinks(container) {
        container.addEventListener('click', function (event) {
            var link = event.target.closest ? event.target.closest('.atlas-entry-tags a') : null;
            if (!link || event.defaultPrevented || event.button !== 0
                || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            var href = link.getAttribute('href') || '';
            var hash = href.indexOf('#') === -1 ? '' : href.slice(href.indexOf('#'));
            // Only same-page links need the nudge; a link elsewhere navigates.
            if (!hash || href.split('#')[0].replace(/\/$/, '') !== window.location.pathname.replace(/\/$/, '')) {
                return;
            }
            event.preventDefault();
            if (window.location.hash === hash) {
                window.location.reload();
            } else {
                window.location.hash = hash;
                window.location.reload();
            }
        });
    }

    /**
     * Essay figures: click any plate to expand it to page width in place.
     *
     * The plate is a <button> (see common/atlas/plate.phtml), so this only has
     * to flip one class - the three widths and the expanded width are all in
     * CSS, which is why the same handler serves a margin figure, a text figure
     * and a page figure without knowing which is which. No overlay, no
     * lightbox: the figure grows where it stands and pushes the text down.
     *
     * The image is held still while that happens - its centre is pinned to the
     * same point of the viewport - so expanding a figure the reader is looking
     * at does not throw the page. A pair expands as one figure, and every
     * plate in it reports the state.
     */
    function essayFigures() {
        var page = document.querySelector('.atlas-essay-page');
        if (!page) {
            return;
        }
        page.addEventListener('click', function (event) {
            var button = event.target.closest ? event.target.closest('.atlas-plate-zoom') : null;
            if (!button || event.defaultPrevented) {
                return;
            }
            var figure = button.closest('.atlas-figure');
            if (!figure) {
                return;
            }
            event.preventDefault();
            var image = button.querySelector('img');
            var before = image ? image.getBoundingClientRect() : null;
            var expanded = figure.classList.toggle('is-expanded');
            var buttons = figure.querySelectorAll('.atlas-plate-zoom');
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].setAttribute('aria-expanded', expanded ? 'true' : 'false');
            }
            if (before) {
                var after = image.getBoundingClientRect();
                window.scrollBy(0, (after.top + after.height / 2) - (before.top + before.height / 2));
            }
        });
    }

    /**
     * Entry page: copy buttons, citation panel, lightbox popup.
     */
    function entryPage() {
        var copies = document.querySelectorAll('.atlas-copy');
        for (var i = 0; i < copies.length; i++) {
            copies[i].addEventListener('click', function () {
                var button = this;
                var text = button.getAttribute('data-copy') || '';
                var done = function () {
                    button.textContent = button.getAttribute('data-done') || 'Copied';
                    button.classList.add('is-done');
                    window.setTimeout(function () {
                        button.textContent = button.getAttribute('data-label');
                        button.classList.remove('is-done');
                    }, 1800);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done);
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.setAttribute('readonly', '');
                    ta.style.position = 'fixed';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); } catch (e) {}
                    document.body.removeChild(ta);
                    done();
                }
            });
        }

        var specimen = document.getElementById('atlas-specimen');
        if (specimen && typeof window.lightGallery === 'function' && specimen.querySelector('.atlas-specimen-link')) {
            var plugins = [];
            if (window.lgZoom) { plugins.push(window.lgZoom); }
            if (window.lgThumbnail) { plugins.push(window.lgThumbnail); }
            window.lightGallery(specimen, {
                selector: '.atlas-specimen-link',
                plugins: plugins,
                thumbnail: true,
                exThumbImage: 'data-thumb',
                download: false,
                mobileSettings: {controls: true, showCloseIcon: true, download: false},
                speed: 160,
                backdropDuration: 160
            });
            // IIIF plates (1.14.1): the HI RES badge opens the plate's lightbox
            // slide - the OpenSeadragon viewer in an iframe - instead of
            // following its no-JS fallback link to the media page.
            var badges = specimen.querySelectorAll('.atlas-plate.is-iiif .atlas-plate-badge');
            for (var b = 0; b < badges.length; b++) {
                badges[b].addEventListener('click', function (event) {
                    var figure = this.parentNode;
                    var link = figure ? figure.querySelector('.atlas-specimen-link') : null;
                    if (link) {
                        event.preventDefault();
                        link.click();
                    }
                });
            }
        }
    }
})();
