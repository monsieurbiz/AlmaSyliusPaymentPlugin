<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Helper;

use Alma\API\Endpoints\Results\Eligibility;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\DataBuilder\EligibilityDataBuilder;
use Payum\Core\Bridge\Spl\ArrayObject;
use Sylius\Component\Core\Model\PaymentMethodInterface;

final class EligibilityHelper
{
    public function __construct(
        private AlmaBridgeInterface $almaBridge,
        private EligibilityDataBuilder $eligibilityDataBuilder,
    ) {
    }

    /**
     * @return Eligibility|Eligibility[]|array
     */
    public function getEligibilities(
        int $amount,
        int $installmentCounts,
        string $billingCountryCode,
        string $shippingCountryCode,
        string $locale,
    ) {
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

    public function initializeConfig(PaymentMethodInterface $method): void
    {
        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = $method->getGatewayConfig();
        $config = ArrayObject::ensureArrayObject($gatewayConfig->getConfig());
        $this->almaBridge->initialize($config);
    }
}
