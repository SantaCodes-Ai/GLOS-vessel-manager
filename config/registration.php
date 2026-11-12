<?php

return [
    // Empty means all active, eligible administrators (including legacy role aliases); otherwise restrict to these account IDs.
    'reviewer_ids' => array_values(array_filter(explode(',', (string) env('REGISTRATION_REVIEWER_IDS', '')))),
];
