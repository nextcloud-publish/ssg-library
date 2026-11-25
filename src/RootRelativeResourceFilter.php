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
 * Rejects embedded resources (`<img src>`, CSS `url()`) addressed by an absolute path such
 * as `/some/path?x`. A published site is a directory somewhere below the host's root, so
 * nothing it ships lives at a root-relative URL; such an embed only makes every visitor
 * request another path on the hosting origin. Links (`<a href>`) and relative URLs such as
 * `.attachments.1/photo.png` pass, and external hosts are left to
 * `URI.DisableExternalResources`.
 */
class RootRelativeResourceFilter extends HTMLPurifier_URIFilter
{
    /** @var string */
    public $name = 'SsgLab.RootRelativeResource';

    /**
     * @param HTMLPurifier_URI $uri
     * @param HTMLPurifier_Config $config
     * @param HTMLPurifier_Context $context
     */
    public function filter(&$uri, $config, $context): bool
    {
        if (!$context->get('EmbeddedURI', true)) {
            return true;
        }

        $rootRelative = $uri->scheme === null && $uri->host === null
            && is_string($uri->path) && str_starts_with($uri->path, '/');

        return !$rootRelative;
    }
}
