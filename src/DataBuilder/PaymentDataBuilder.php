<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\DataBuilder;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;

class PaymentDataBuilder implements PaymentDataBuilderInterface
{
    /**
     * @var array<array-key, DataBuilderInterface|callable>
     */
    private $extraBuilders = [];

    public function __construct(private ServiceRegistryInterface $buildersRegistry)
    {
    }

    /**
     * @param DataBuilderInterface|callable $builder
     */
    public function addBuilder($builder): void
    {
        $this->extraBuilders[] = $builder;
    }

    /**
     * @return array<array-key, DataBuilderInterface|callable>
     */
    private function getAllBuilders(): array
    {
        return array_merge($this->buildersRegistry->all(), $this->extraBuilders);
    }

    public function __invoke(array $data, PaymentInterface $payment): array
    {
        $data['payment'] = [
            'purchase_amount' => $payment->getAmount(),
            'custom_data' => [
                'payment_id' => $payment->getId(),
            ],
        ];

        /** @var DataBuilderInterface $builder */
        foreach ($this->getAllBuilders() as $builder) {
            $data = $builder($data, $payment);
        }

        return $data;
    }
}
