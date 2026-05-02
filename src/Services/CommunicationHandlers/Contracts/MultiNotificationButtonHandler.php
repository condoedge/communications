<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

/**
 * Marker interface for notification-button handlers that emit more than one button.
 *
 * The card render path checks `instanceof MultiNotificationButtonHandler` and dispatches
 * to `getButtons()` returning an array of Kompo Elements. Single-button handlers continue
 * to extend `Kompo\Auth\Models\Monitoring\AbstractNotificationButtonHandler` and return one
 * Element from `getButton()`.
 */
interface MultiNotificationButtonHandler
{
    /**
     * @return array<\Kompo\Elements\Element>
     */
    public function getButtons(): array;
}
