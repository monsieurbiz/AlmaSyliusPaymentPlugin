<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class AlmaSyliusPaymentPlugin extends Bundle
{
    const VERSION = "1.0.0";

    use SyliusPluginTrait;
}
