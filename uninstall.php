<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// To preserve data, the analytics table is not automatically deleted on uninstall.
// To remove everything, delete wp_tya_events and tya_* options manually.options manually.
