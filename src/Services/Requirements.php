<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services;

/**
 * Minimum a-blog cms version. 3.2.38 is the first release whose admin.js shares react-dom and the design
 * system components with plugins (ACMS.Library.ReactDom / ReactDomClient / components), so the admin UI no
 * longer bundles React and the components.
 */
final class Requirements
{
    public const MIN_ACMS_VERSION = '3.2.38';

    /**
     * An unknown version (constant not defined) is not blocked, since it cannot be verified.
     */
    public static function isSupported(mixed $acmsVersion): bool
    {
        if (!is_string($acmsVersion) || $acmsVersion === '') {
            return true;
        }

        return version_compare($acmsVersion, self::MIN_ACMS_VERSION, '>=');
    }
}
