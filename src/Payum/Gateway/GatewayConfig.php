<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Gateway;

use Alma\API\Client as AlmaClient;
use Payum\Core\Bridge\Spl\ArrayObject;

class GatewayConfig implements GatewayConfigInterface
{
    /**
     * @var ArrayObject
     */
    private $config;

    public function __construct(ArrayObject $config)
    {
        $this->config = $config;
    }

    public function getApiMode(): string
    {
        return $this->config[self::CONFIG_API_MODE];
    }

    public function getMerchantId(): string
    {
        return $this->config[self::CONFIG_MERCHANT_ID];
    }

    public function getActiveApiKey(): string
    {
        return AlmaClient::LIVE_MODE == $this->getApiMode() ? $this->getLiveApiKey() : $this->getTestApiKey();
    }

    public function getLiveApiKey(): string
    {
        return $this->config[self::CONFIG_LIVE_API_KEY];
    }

    public function getTestApiKey(): string
    {
        return $this->config[self::CONFIG_TEST_API_KEY];
    }

    public function getInstallmentsCount(): int
    {
        return (int) $this->config[self::CONFIG_INSTALLMENTS_COUNT];
    }

    public function getPaymentFormTemplate(): string
    {
        return $this->config[self::CONFIG_PAYMENT_FORM_TEMPLATE];
    }

    public function getPaymentPageMode(): string
    {
        return $this->config[self::CONFIG_PAYMENT_PAGE_MODE];
    }
}
