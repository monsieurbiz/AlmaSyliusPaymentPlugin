<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\DataBuilder;

use Alma\SyliusPaymentPlugin\DataBuilder\EligibilityDataBuilder;
use PHPUnit\Framework\TestCase;

final class EligibilityDataBuilderTest extends TestCase
{
    public function testTwentyFourInstallmentsPayloadPreservesAmountCountriesAndLocale(): void
    {
        self::assertSame([
            'purchase_amount' => 240001,
            'queries' => [[
                'installments_count' => 24,
                'deferred_days' => '0',
                'deferred_months' => '0',
            ]],
            'billing_address' => ['country' => 'FR'],
            'shipping_address' => ['country' => 'BE'],
            'locale' => 'fr',
        ], (new EligibilityDataBuilder())(240001, 24, 'FR', 'BE', 'fr'));
    }
}
