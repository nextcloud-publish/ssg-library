/*
 * Light/dark theme toggle.
 *
 * There is exactly one piece of state: the `data-theme` attribute on <html>,
 * which is either "light" or "dark". Everything else reads from it — the CSS
 * pins `color-scheme` to it, and the button's label and icon derive from it.
 *
 * Where that value comes from, in order:
 *   1. a previous click, remembered in localStorage
 *   2. otherwise the OS setting, which it keeps following until the first click
 *
 * Loaded as a blocking <script> in <head> so the attribute is set before the
 * first paint; deferring it would flash a light page at dark-theme visitors.
 */
(function () {
	'use strict';

	var KEY = 'ssg-lab-theme';
	var root = document.documentElement;
	var prefersDark = matchMedia('(prefers-color-scheme: dark)');

	// localStorage throws rather than no-ops in some privacy modes, so both
	// accesses are guarded: a blocked store must not take the page down.
	function readSaved() {
		try {
			return localStorage.getItem(KEY);
		} catch (error) {
			return null;
		}
	}

	function save(theme) {
		try {
			localStorage.setItem(KEY, theme);
		} catch (error) {
			// The choice just won't outlive this page.
		}
	}

	function setTheme(theme) {
		root.dataset.theme = theme;
	}

	function systemTheme() {
		return prefersDark.matches ? 'dark' : 'light';
	}

	setTheme(readSaved() || systemTheme());

	prefersDark.addEventListener('change', function () {
		if (!readSaved()) {
			setTheme(systemTheme());
		}
	});

	document.addEventListener('DOMContentLoaded', function () {
		var button = document.querySelector('.theme-toggle');

		function describe() {
			button.setAttribute(
				'aria-label',
				root.dataset.theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme',
			);
		}

		// Ships hidden in the HTML: without this script the button cannot
		// work, so a no-JS visitor should never see it.
		button.hidden = false;
		describe();

		button.addEventListener('click', function () {
			var next = root.dataset.theme === 'dark' ? 'light' : 'dark';
			setTheme(next);
			save(next);
			describe();
		});
	});
})();
