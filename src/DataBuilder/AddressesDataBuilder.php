<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\DataBuilder;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Webmozart\Assert\Assert;

class AddressesDataBuilder implements DataBuilderInterface
{
    public function __invoke(array $data, PaymentInterface $payment): array
    {
        $order = $payment->getOrder();
        Assert::notNull($order);

        $addresses = ['shipping' => $order->getShippingAddress(), 'billing' => $order->getBillingAddress()];
        foreach ($addresses as $type => $address) {
            if (null !== $address) {
                $data['payment'][$type . '_address'] = $this->getAddressData($address);
            }
        }

        return $data;
    }

    private function getAddressData(AddressInterface $address): array
    {
        return [
            'first_name' => $address->getFirstName(),
            'last_name' => $address->getLastName(),
            'line1' => $address->getStreet(),
            'postal_code' => $address->getPostcode(),
            'city' => $address->getCity(),
            'country' => $address->getCountryCode(),
            'phone' => $address->getPhoneNumber(),
        ];
    }
}
