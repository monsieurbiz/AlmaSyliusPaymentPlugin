<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin;

use Alma\SyliusPaymentPlugin\DependencyInjection\Compiler\RegisterDataBuildersPass;
use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class AlmaSyliusPaymentPlugin extends Bundle
{
    const VERSION = "1.3.0";

    use SyliusPluginTrait;

    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new RegisterDataBuildersPass());
    }
}
