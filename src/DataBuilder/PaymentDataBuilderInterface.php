<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\DataBuilder;

use Sylius\Component\Registry\ServiceRegistryInterface;

interface PaymentDataBuilderInterface extends DataBuilderInterface
{
    public function __construct(ServiceRegistryInterface $buildersRegistry);

    /**
     * @param DataBuilderInterface|callable $builder
     */
    public function addBuilder($builder): void;
}
