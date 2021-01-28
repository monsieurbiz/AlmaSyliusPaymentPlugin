<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\ValueObject\Customer;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Convert;
use Sylius\Component\Core\Model\PaymentInterface;

final class ConvertPaymentAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait;

    /**
     * @var AlmaBridge
     */
    protected $api;

    public function __construct()
    {
        $this->apiClass = AlmaBridge::class;
    }

    /**
     * @param Convert $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getSource();
        $config = $this->api->getGatewayConfig();

        $order = $payment->getOrder();
        $shipping = $order->getShippingAddress();
        $billing = $order->getBillingAddress();
        $customer = Customer::fromOrder($order);

        // TODO: add: ipn_callback_url, customer_cancel_url, shipping info, cart content
        $paymentData = [
            'payment' => [
                'purchase_amount' => $payment->getAmount(),
                'installments_count' => $config->getInstallmentsCount(),
                'return_url' => $request->getToken()->getAfterUrl(),
            ],
            'order' => [
                'merchant_reference' => $order->getNumber()
            ],
            'customer' => [
                'first_name' => $customer->getFirstName(),
                'last_name' => $customer->getLastName(),
                'email' => $customer->getEmail(),
                'phone' => $customer->getPhoneNumber(),
            ],
        ];

        $addresses = ['shipping' => $shipping, 'billing' => $billing];
        foreach ($addresses as $type => $address) {
            if ($address !== null) {
                $paymentData['payment']["${type}_address"] = [
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

        $request->setResult(['payload' => $paymentData]);
    }

    public function supports($request): bool
    {
        return
            $request instanceof Convert &&
            $request->getSource() instanceof PaymentInterface &&
            $request->getTo() === 'array';
    }
}
