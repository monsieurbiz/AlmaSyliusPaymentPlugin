<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\DataBuilder;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Webmozart\Assert\Assert;

class CustomerDataBuilder implements DataBuilderInterface
{
    public function __invoke(array $data, PaymentInterface $payment): array
    {
        $order = $payment->getOrder();
        Assert::notNull($order);

        $data['customer'] = self::extractCustomerData($order);

        return $data;
    }

    /**
     * @psalm-type CustomerData = array{firstName: string, lastName: string, email:String, phoneNumber: String}
     *
     * @return array
     *
     * @psalm-return CustomerData
     */
    private static function extractCustomerData(OrderInterface $order): array
    {
        $customer = $order->getCustomer();
        $user = $order->getUser();
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $sources = [$customer, $user, $billingAddress, $shippingAddress];
        $fields = [
            'first_name' => 'getFirstName',
            'last_name' => 'getLastName',
            'email' => 'getEmail',
            'phone' => 'getPhoneNumber',
        ];

        /** @var CustomerData $result */
        $result = ['first_name' => null, 'last_name' => null, 'email' => null, 'phone' => null];

        foreach ($fields as $field => $getter) {
            foreach ($sources as $source) {
                $callable = [$source, $getter];
                if (\is_callable($callable)) {
                    /** @var string|false|null $value */
                    $value = \call_user_func($callable);

                    if (false !== $value && null !== $value && '' !== $value) {
                        $result[$field] = $value;
                        break;
                    }
                }
            }
        }

        return $result;
    }
}
