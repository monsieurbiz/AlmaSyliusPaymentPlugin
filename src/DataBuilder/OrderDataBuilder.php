<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\DataBuilder;


use Sylius\Component\Core\Model\PaymentInterface;
use Webmozart\Assert\Assert;

class OrderDataBuilder implements DataBuilderInterface
{

    public function __invoke(array $data, PaymentInterface $payment): array
    {
        $order = $payment->getOrder();
        Assert::notNull($order);

        $data['order'] = [
            'merchant_reference' => $order->getNumber()
        ];

        return $data;
    }
}
