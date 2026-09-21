<?php

declare(strict_types=1);

return [
    'sso_failed' => 'Не удалось открыть Linkado. Пожалуйста, попробуйте ещё раз.',
    'retry_operator_required' => 'Параметр --operator не должен быть пустым.',
    'retry_reason_required' => 'Параметр --reason не должен быть пустым.',
    'retry_mode_off' => 'Доставка событий Linkado отключена.',
    'retry_not_found' => 'Событие Linkado не найдено.',
    'retry_shadow' => 'Событие Linkado в теневом режиме нельзя отправить повторно.',
    'retry_delivered' => 'Доставленное событие Linkado нельзя отправить повторно.',
    'retry_active' => 'Событие Linkado уже ожидает отправки или доставляется.',
    'retry_corrupt' => 'Событие Linkado с повреждённой нагрузкой нельзя отправить повторно.',
    'retry_conflict' => 'Событие Linkado, отклонённое с HTTP 409, нельзя отправить повторно.',
];
