<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Bridge;

use Alma\API\Client;
use Alma\API\Endpoints\Results\Eligibility;
use Alma\API\Entities\Merchant;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfig;
use Payum\Core\Bridge\Spl\ArrayObject;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;

interface AlmaBridgeInterface
{
    function initialize(ArrayObject $config): void;

    function getGatewayConfig(): GatewayConfig;

    function getDefaultClient(string $mode): ?Client;
    static function createClientInstance(string $apiKey, string $mode, LoggerInterface $logger): ?Client;

    function getMerchantInfo(): ?Merchant;

    /**
     * @param PaymentInterface $payment
     * @param int[] $installmentsCounts
     * @return Eligibility[]
     */
    function getEligibilities(PaymentInterface $payment, array $installmentsCounts): array;
}
