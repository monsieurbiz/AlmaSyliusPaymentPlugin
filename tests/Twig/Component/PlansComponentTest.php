<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\Twig\Component;

use Alma\SyliusPaymentPlugin\Twig\Component\PlansComponent;
use PHPUnit\Framework\TestCase;
use Symfony\UX\LiveComponent\LiveResponder;

final class PlansComponentTest extends TestCase
{
    public function testInitialAndRepeatedDispatchKeepTheLatestIntegerAmountLocaleAndPlans(): void
    {
        $component = new PlansComponent();
        $responder = new LiveResponder();
        $component->setLiveResponder($responder);
        $component->amount = 12345;
        $component->locale = 'fr_FR';
        $component->plans = [['installmentsCount' => 3, 'minAmount' => 10001, 'maxAmount' => 300001]];
        $component();
        self::assertSame([[
            'event' => 'alma:plans:mounted',
            'payload' => ['amount' => 12345, 'plans' => $component->plans, 'locale' => 'fr_FR'],
        ]], $responder->getBrowserEventsToDispatch());

        $responder->reset();
        $component->amount = 37035;
        $component->locale = 'nl_BE';
        $component->plans = [['installmentsCount' => 24, 'minAmount' => 20001, 'maxAmount' => 600001]];
        $component->dispatch();
        self::assertSame([[
            'event' => 'alma:plans:mounted',
            'payload' => ['amount' => 37035, 'plans' => $component->plans, 'locale' => 'nl_BE'],
        ]], $responder->getBrowserEventsToDispatch());
    }

    public function testMissingRequiredDataNeverDispatchesAWidgetEvent(): void
    {
        foreach ([
            [null, 'fr_FR', [['installmentsCount' => 3]]],
            [12345, null, [['installmentsCount' => 3]]],
            [12345, 'fr_FR', []],
        ] as [$amount, $locale, $plans]) {
            $component = new PlansComponent();
            $responder = new LiveResponder();
            $component->setLiveResponder($responder);
            $component->amount = $amount;
            $component->locale = $locale;
            $component->plans = $plans;
            $component();
            $component->dispatch();
            self::assertSame([], $responder->getBrowserEventsToDispatch());
        }
    }
}
