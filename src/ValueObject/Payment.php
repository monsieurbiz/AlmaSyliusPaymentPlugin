<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\ValueObject;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Webmozart\Assert\Assert;

final class Payment
{
    /**
     * @var PaymentInterface
     */
    private $payment;

    /**
     * @var OrderInterface
     */
    private $order;
    /**
     * @var Customer
     */
    private $customer;

    static public function fromOrderPayment(PaymentInterface $payment): self {
        $order = $payment->getOrder();
        Assert::notNull($order);

        return new self($payment, $order, Customer::fromOrder($order));
    }

    public function __construct(PaymentInterface $payment, OrderInterface $order, Customer $customer)
    {
        $this->payment = $payment;
        $this->order = $order;
        $this->customer = $customer;
    }

    /**
     * @return array[]
     */
    public function getPayloadData(): array
    {
        // TODO: add locale, shipping info, cart content
        $data = [
            'payment' => [
                'purchase_amount' => $this->payment->getAmount(),
            ],
            'order' => [
                'merchant_reference' => $this->order->getNumber()
            ],
            'customer' => [
                'first_name' => $this->customer->getFirstName(),
                'last_name' => $this->customer->getLastName(),
                'email' => $this->customer->getEmail(),
                'phone' => $this->customer->getPhoneNumber(),
            ],
        ];

        $shipping = $this->order->getShippingAddress();
        $billing = $this->order->getBillingAddress();

        $addresses = ['shipping' => $shipping, 'billing' => $billing];
        foreach ($addresses as $type => $address) {
            if ($address !== null) {
                $data['payment']["${type}_address"] = [
                    'first_name' => $address->getFirstName(),
                    'last_name' => $address->getLastName(),
                    'line1' => $address->getStreet(),
                    'postal_code' => $address->getPostcode(),
                    'city' => $address->getCity(),
                    'country' => $address->getCountryCode(),
                    'phone' => $address->getPhoneNumber()
                ];
            }
        }

        return $data;
    }
}
