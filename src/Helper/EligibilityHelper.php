<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Helper;


use Alma\API\Endpoints\Results\Eligibility;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\DataBuilder\EligibilityDataBuilder;

final class EligibilityHelper
{
    /**
     * @var AlmaBridgeInterface
     */
    private $almaBridge;

    /**
     * @var EligibilityDataBuilder
     */
    private $eligibilityDataBuilder;

    /**
     * @param AlmaBridgeInterface $almaBridge
     * @param EligibilityDataBuilder $eligibilityDataBuilder
     */
    public function __construct(
        AlmaBridgeInterface $almaBridge,
        EligibilityDataBuilder $eligibilityDataBuilder
    ) {
        $this->almaBridge = $almaBridge;
        $this->eligibilityDataBuilder = $eligibilityDataBuilder;
    }

    /**
     * @param int $amount
     * @param int $installmentCounts
     * @param string $billingCountryCode
     * @param string $shippingCountryCode
     * @param string $locale
     * @return Eligibility|Eligibility[]|array
     */
    public function getEligibilities(
        int $amount,
        int $installmentCounts,
        string $billingCountryCode,
        string $shippingCountryCode,
        string $locale
    )
    {
        $data = $this->eligibilityDataBuilder;

        return $this->almaBridge->retrieveEligibilities(
            $data(
                $amount,
                $installmentCounts,
                $billingCountryCode,
                $shippingCountryCode,
                $locale
            )
        );
    }
}