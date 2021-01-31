<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\DataBuilder;


use Sylius\Component\Core\Model\PaymentInterface;

class PaymentDataBuilder implements PaymentDataBuilderInterface
{
    /**
     * @var DataBuilderInterface[]|array
     */
    private $builders;

    /**
     * @inheritDoc
     */
    public function __construct(array $builders)
    {
        $this->builders = $builders;
    }

    /**
     * @param DataBuilderInterface|callable $builder
     */
    public function addBuilder($builder): void
    {
        $this->builders[] = $builder;
    }

    public function __invoke(array $data, PaymentInterface $payment): array
    {
        $data['payment'] = [
            'purchase_amount' => $payment->getAmount(),
        ];

        /** @var DataBuilderInterface $builder */
        foreach ($this->builders as $builder) {
            $data = $builder($data, $payment);
        }

        return $data;
    }
}
