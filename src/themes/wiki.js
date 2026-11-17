/*
 * The `wiki` layout's sidebar drawer, copied to the build output as
 * `layout.js`.
 *
 * There is exactly one piece of state: the `data-theme`-style `data-nav`
 * attribute on <html>, which is either "open" or "closed". Setting it at all
 * is what tells `layout.css` a drawer is possible — the stylesheet keys every
 * drawer rule off it, and falls back to showing the tree as a band above the
 * page when it is absent, so a visitor without this script is never left with
 * a navigation nothing can open.
 *
 * Where the drawer applies is the stylesheet's decision alone: the breakpoint
 * is written once, in `wiki.css`. Above it the toggle is display:none and the
 * tree is simply the sidebar, which is why nothing here asks how wide the
 * window is.
 *
 * Loaded as a blocking <script> in <head> so the attribute is set before the
 * first paint; deferring it would flash the band at every narrow-screen
 * visitor on the way to the drawer.
 */
(function () {
    'use strict';

    var root = document.documentElement;

    function setOpen(open) {
        root.dataset.nav = open ? 'open' : 'closed';
    }

    setOpen(false);

    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('.nav-toggle');
        var scrim = document.querySelector('.nav-scrim');
        var sidebar = document.querySelector('.sidebar');

        function open() {
            setOpen(true);
            toggle.setAttribute('aria-expanded', 'true');
            // The drawer now covers the page, so the focus follows it in:
            // the next Tab or Enter should land where the eye already is.
            var first = sidebar.querySelector('a');
            if (first) {
                // The closed drawer is `visibility: hidden`, and nothing
                // inside something hidden can take focus. Reading a layout
                // property settles the style the attribute above just
                // changed, so the link is already visible when asked.
                void sidebar.offsetWidth;
                first.focus();
            }
        }

        function close() {
            setOpen(false);
            toggle.setAttribute('aria-expanded', 'false');
            // Back to the control the drawer was opened from — the focus may
            // be on a link that is about to stop being reachable.
            toggle.focus();
        }

        // Ships hidden in the HTML: without this script the drawer cannot be
        // opened, so a no-JS visitor should never see the button.
        toggle.hidden = false;

        toggle.addEventListener('click', function () {
            if (root.dataset.nav === 'open') {
                close();
            } else {
                open();
            }
        });

        // Clicking the page behind the drawer means "back to reading".
        scrim.addEventListener('click', close);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && root.dataset.nav === 'open') {
                close();
            }
        });
    });
})();
