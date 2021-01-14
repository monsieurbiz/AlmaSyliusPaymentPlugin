<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum;

use Payum\Core\GatewayFactory;
use Payum\Core\Bridge\Spl\ArrayObject;
use Alma\SyliusPaymentPlugin\Payum\Action\StatusAction;

final class AlmaGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => 'alma_payments',
            'payum.factory_title' => 'Alma Payments',
            'payum.action.status' => new StatusAction(),
        ]);

        $config['payum.api'] = function (ArrayObject $config): AlmaApi {
            return new AlmaApi($config);
        };
    }
}
