<?php

declare(strict_types=1);

return [
    'sso_failed' => 'Unable to open Linkado right now. Please try again.',
    'retry_operator_required' => 'The --operator option must be a non-empty value.',
    'retry_reason_required' => 'The --reason option must be a non-empty value.',
    'retry_mode_off' => 'Linkado delivery is disabled.',
    'retry_not_found' => 'The Linkado event was not found.',
    'retry_shadow' => 'Shadow Linkado events cannot be retried.',
    'retry_delivered' => 'Delivered Linkado events cannot be retried.',
    'retry_active' => 'The Linkado event is already pending or delivering.',
    'retry_corrupt' => 'A Linkado event with a corrupt payload cannot be retried.',
    'retry_conflict' => 'A Linkado event rejected with HTTP 409 cannot be retried.',
];
