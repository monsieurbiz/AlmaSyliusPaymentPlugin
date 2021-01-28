<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Bridge;

use Alma\API\Client;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfig;
use Payum\Core\Bridge\Spl\ArrayObject;
use Psr\Log\LoggerInterface;

interface AlmaBridgeInterface
{
    function initialize(ArrayObject $config): void;

    function getGatewayConfig(): GatewayConfig;

    function getDefaultClient(string $mode): ?Client;
    static function createClientInstance(string $apiKey, string $mode, LoggerInterface $logger): ?Client;
}
