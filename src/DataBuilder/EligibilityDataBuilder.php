<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\DataBuilder;

class EligibilityDataBuilder
{
    public function __invoke(
        int $amount,
        int $installmentCounts,
        string $billingCountryCode,
        string $shippingCountryCode,
        string $locale,
    ): array {
        return [
            'purchase_amount' => $amount,
            'queries' => [
                [
                    'installments_count' => $installmentCounts,
                    'deferred_days' => '0',
                    'deferred_months' => '0',
                ],
            ],
            'billing_address' => [
                'country' => $billingCountryCode,
            ],
            'shipping_address' => [
                'country' => $shippingCountryCode,
            ],
            'locale' => $locale,
        ];
    }
}
