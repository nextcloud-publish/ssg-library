/*
 * The navigation drawer, copied to the build output as `nav.js` and linked
 * from every page: on a narrow screen both layouts put their navigation
 * behind a button in the site header rather than on the page itself, and this
 * is what opens it.
 *
 * There is exactly one piece of state: the `data-theme`-style `data-nav`
 * attribute on <html>, which is either "open" or "closed". Setting it at all
 * is what tells `layout.css` a drawer is possible — each layout keys every
 * drawer rule off it, and falls back to leaving its navigation on the page
 * where it already was, so a visitor without this script is never left with a
 * navigation nothing can open.
 *
 * Where the drawer applies and which way it slides is the stylesheet's
 * decision alone: each layout writes its own breakpoint once, in its own
 * `themes/*.css` — `wiki` slides its sidebar in from the left, `topnav` its
 * bars down from behind the site header. Above that width the toggle is
 * display:none and the navigation is simply there, which is why nothing here
 * asks how wide the window is.
 *
 * Loaded as a blocking <script> in <head> so the attribute is set before the
 * first paint; deferring it would flash the navigation at every narrow-screen
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
        // The id SiteBuilder gives whichever element holds the navigation:
        // `wiki`'s sidebar, or the box around `topnav`'s two bars.
        var drawer = document.getElementById('site-nav');

        if (!toggle || !scrim || !drawer) {
            // A page with no navigation at all — a single-page site under
            // `topnav`. There is nothing to open, and the attribute set above
            // would only ask the stylesheet to hide what isn't there.
            delete root.dataset.nav;
            return;
        }

        function open() {
            setOpen(true);
            toggle.setAttribute('aria-expanded', 'true');
            // The drawer now covers the page, so the focus follows it in:
            // the next Tab or Enter should land where the eye already is.
            var first = drawer.querySelector('a');
            if (first) {
                // The closed drawer is `visibility: hidden`, and nothing
                // inside something hidden can take focus. Reading a layout
                // property settles the style the attribute above just
                // changed, so the link is already visible when asked.
                void drawer.offsetWidth;
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
