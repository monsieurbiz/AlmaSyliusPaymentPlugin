<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin;

use Alma\SyliusPaymentPlugin\DependencyInjection\Compiler\RegisterDataBuildersPass;
use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class AlmaSyliusPaymentPlugin extends Bundle
{
    use SyliusPluginTrait;
    public const VERSION = '2.1.0';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RegisterDataBuildersPass());
    }
}
