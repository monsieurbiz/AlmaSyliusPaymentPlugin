<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Gateway;

use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;

final class AlmaGatewayFactory extends GatewayFactory
{
    const FACTORY_NAME = 'alma_payments';

    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => self::FACTORY_NAME,
            'payum.factory_title' => 'Alma Payments',
            GatewayConfigInterface::CONFIG_INSTALLMENTS_COUNT => 3,
            GatewayConfigInterface::CONFIG_PAYMENT_PAGE_MODE => GatewayConfigInterface::PAYMENT_PAGE_MODE_REDIRECT,
            GatewayConfigInterface::CONFIG_PAYMENT_FORM_TEMPLATE => '@AlmaSyliusPaymentPlugin/Action/payment_form.html.twig',
        ]);

        // Set payum.http_client to our own API client bridge, which will be initialized with the gateway's config and
        // used as `payum.api` when the closure for this configuration value is called, as Payum will have transformed
        // the payum.http_client entry to the service's instance by then
        $config['payum.http_client'] = '@alma_sylius_payment_plugin.bridge';

        $config['payum.api'] = function (ArrayObject $config): AlmaBridgeInterface {
            /** @var AlmaBridgeInterface $almaBridge */
            $almaBridge = $config['payum.http_client'];
            $almaBridge->initialize($config);

            return $almaBridge;
        };
    }
}
