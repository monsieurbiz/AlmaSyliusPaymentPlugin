<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class RegisterDataBuildersPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('alma_sylius_payment_plugin.registry.payment_data_builder')) {
            return;
        }

        $registry = $container->getDefinition('alma_sylius_payment_plugin.registry.payment_data_builder');

        foreach ($container->findTaggedServiceIds('alma_sylius_payment_plugin.payment_data_builder') as $id => $attributes) {
            if (!isset($attributes[0]['id'])) {
                throw new \InvalidArgumentException('Tagged data builder needs to have a `id` attribute.');
            }

            $name = (string)$attributes[0]['id'];
            $registry->addMethodCall('register', [$name, new Reference($id)]);
        }
    }
}
