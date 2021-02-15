<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\DataBuilder;


use Alma\SyliusPaymentPlugin\Utils\Utils;
use Doctrine\Common\Collections\Collection;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Model\ShippingMethodInterface as BaseShippingMethodInterface;
use Sylius\Component\Shipping\Model\ShippingMethodTranslationInterface;
use Sylius\Component\Shipping\Resolver\ShippingMethodsResolverInterface;
use Webmozart\Assert\Assert;

class ShippingInfoDataBuilder implements DataBuilderInterface
{
    /**
     * @var ShippingMethodsResolverInterface
     */
    private $methodsResolver;
    /**
     * @var ServiceRegistryInterface
     */
    private $calculators;

    public function __construct(
        ServiceRegistryInterface $calculators,
        ShippingMethodsResolverInterface $methodsResolver
    ) {
        $this->methodsResolver = $methodsResolver;
        $this->calculators = $calculators;
    }

    public function __invoke(array $data, PaymentInterface $payment): array
    {
        $order = $payment->getOrder();
        Assert::notNull($order);

        if (!$order->hasShipments()) {
            return $data;
        }

        $shipments = $order->getShipments();
        if ($shipments instanceof Collection) {
            $shipments = $shipments->getValues();
        }

        /** @var ShipmentInterface[] $shipments */
        $selectedOptions = $this->getSelectedOptions($shipments);
        $availableOptions = $this->getAvailableOptions($shipments);

        $data['payment']['shipping_info'] = [
            'selected_options' => $selectedOptions,
            'available_options' => $availableOptions
        ];

        return $data;
    }

    /**
     * @param ShipmentInterface[] $shipments
     * @return array[]
     */
    private function getSelectedOptions(array $shipments): array
    {
        return array_map(function (ShipmentInterface $shipment): array {
            /** @var ShippingMethodInterface $method */
            $method = $shipment->getMethod();
            return $this->buildShippingOption($shipment, $method);
        }, $shipments);
    }

    /**
     * @param ShipmentInterface[] $shipments
     * @return array
     */
    private function getAvailableOptions(array $shipments): array
    {
        $options = [];

        foreach ($shipments as $shipment) {
            if (!$this->methodsResolver->supports($shipment)) {
                continue;
            }

            $methods = $this->methodsResolver->getSupportedMethods($shipment);

            foreach ($methods as $method) {
                $options[] = $this->buildShippingOption($shipment, $method);
            }
        }

        return $options;
    }

    private function buildShippingOption(ShipmentInterface $shipment, BaseShippingMethodInterface $method): array
    {
        /** @var ShippingMethodTranslationInterface $methodTranslation */
        $methodTranslation = Utils::getTranslationImpl($method);

        /** @var CalculatorInterface $calculator */
        $calculator = $this->calculators->get($method->getCalculator());

        return [
            'amount' => $calculator->calculate($shipment, $method->getConfiguration()),
            'title' => $methodTranslation->getName(),
            'carrier' => $methodTranslation->getDescription()
        ];
    }
}
