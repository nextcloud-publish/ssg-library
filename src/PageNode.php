<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace SsgLab;

/**
 * One page in the site's tree, as scanned off disk before anything is
 * rendered. Both layouts need to know about pages other than the one being
 * written — the `wiki` sidebar shows the whole tree, the `topnav` bar the
 * current page's children — which the rendering recursion alone can't tell
 * them, since it only ever has the folder it is in.
 *
 * `$slug` is the page's directory name in the output, so a node's URL path is
 * the slugs from the root down to it; the root node's slug is empty.
 */
final class PageNode
{
    /**
     * @param list<PageNode> $children Leaf `.md` sub-pages first, then sub-folders — the order
     *   SiteBuilder renders them in.
     */
    public function __construct(
        public string $slug,
        public string $title,
        public array $children = [],
    ) {
    }
}
