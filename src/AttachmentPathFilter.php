<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace SsgLab;

use HTMLPurifier_Config;
use HTMLPurifier_Context;
use HTMLPurifier_URI;
use HTMLPurifier_URIFilter;

/**
 * Prepends $prefix to relative URLs pointing into an attachments folder, such as
 * `.attachments.1/photo.png`. Markdown references attachments relative to the folder its
 * `.md` file lives in, but a leaf page renders one directory deeper, so its links need `../`.
 *
 * Runs inside HTMLPurifier, so it sees exactly the URLs of the output: `href`, `src` and CSS
 * `url()` alike, and never text that merely looks like one. MarkdownConverter::toHtml() sets
 * $prefix for the page at hand.
 */
class AttachmentPathFilter extends HTMLPurifier_URIFilter
{
    /** @var string */
    public $name = 'SsgLab.AttachmentPath';

    public string $prefix = '';

    /**
     * @param HTMLPurifier_URI $uri
     * @param HTMLPurifier_Config $config
     * @param HTMLPurifier_Context $context
     */
    public function filter(&$uri, $config, $context): bool
    {
        if (
            $this->prefix !== ''
            && $uri->scheme === null
            && $uri->host === null
            && is_string($uri->path)
            && str_starts_with($uri->path, '.attachments.')
        ) {
            $uri->path = $this->prefix . $uri->path;
        }

        return true;
    }
}
