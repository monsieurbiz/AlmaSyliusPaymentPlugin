<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Helper;


use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;

final class EligibilityHelper
{
    /**
     * @var AlmaBridgeInterface
     */
    private $almaBridge;

    /**
     * @param AlmaBridgeInterface $almaBridge
     */
    public function __construct(
        AlmaBridgeInterface $almaBridge
    ) {
        $this->almaBridge = $almaBridge;
    }

    /**
     * @param int $amount
     * @param int $installmentCounts
     * @param string $billingCountryCode
     * @param string $shippingCountryCode
     * @param string $locale
     * @return \Alma\API\Endpoints\Results\Eligibility|\Alma\API\Endpoints\Results\Eligibility[]|array
     */
    public function getEligibilities(int $amount, int $installmentCounts, string $billingCountryCode, string $shippingCountryCode, string $locale)
    {
        $data = [
            "purchase_amount" => $amount,
            "queries" => [
                [
                    "installments_count" =>  $installmentCounts,
                    "deferred_days" =>  "0",
                    "deferred_months" =>  "0"
                ]
            ],
            "billing_address" => [
                "country" => $billingCountryCode,
            ],
            "shipping_address" => [
                "country" => $shippingCountryCode
            ],
            "locale" => $locale,
        ];

        return $this->almaBridge->retrieveEligibilities($data);
    }
}