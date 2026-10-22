<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Agent;

use Murkrow\FilamentAi\Agent\PanelAssistant;

/**
 * An application's assistant with tools of its own.
 */
class TestHostAssistant extends PanelAssistant
{
    protected function additionalTools(): iterable
    {
        return [new CountBooks, new RetitleBooks, new ExportBooks];
    }
}
