<?php


namespace Alma\SyliusPaymentPlugin\DataBuilder;


interface PaymentDataBuilderInterface extends DataBuilderInterface
{
    /**
     * @param DataBuilderInterface[] $builders
     */
    public function __construct(array $builders);

    /**
     * @param DataBuilderInterface|callable $builder
     */
    public function addBuilder($builder): void;
}
