<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\DataBuilder;

use Sylius\Component\Core\Model\PaymentInterface;

interface DataBuilderInterface
{
    public function __invoke(array $data, PaymentInterface $payment): array;
}
