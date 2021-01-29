<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Bridge;


use Alma\API\Client;
use Alma\API\Entities\Merchant;
use Alma\API\RequestError;
use Alma\SyliusPaymentPlugin\AlmaSyliusPaymentPlugin;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfig;
use Alma\SyliusPaymentPlugin\ValueObject\Payment;
use Exception;
use Payum\Core\Bridge\Spl\ArrayObject;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\CoreBundle\Application\Kernel as Sylius;
use Sylius\Component\Core\Model\PaymentInterface;

final class AlmaBridge implements AlmaBridgeInterface
{
    /**
     * @var GatewayConfig
     */
    private $gatewayConfig = null;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Client|null
     */
    static private $almaClient;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function initialize(ArrayObject $config): void
    {
        self::$almaClient = null;
        $this->gatewayConfig = new GatewayConfig($config);
    }

    public function getDefaultClient(?string $mode = null): Client
    {
        if ($mode === null) {
            $mode = $this->gatewayConfig->getApiMode();
        }

        if (!self::$almaClient) {
            self::$almaClient = self::createClientInstance(
                $this->gatewayConfig->getActiveApiKey(),
                $mode,
                $this->logger
            );
        }

        return self::$almaClient;
    }

    public static function createClientInstance(string $apiKey, string $mode, LoggerInterface $logger): ?Client
    {
        /** @var Client|null $alma */
        $alma = null;

        try {
            $alma = new Client($apiKey, [
                'mode' => $mode,
                'logger' => $logger
            ]);

            $alma->addUserAgentComponent('Sylius', Sylius::VERSION);
            $alma->addUserAgentComponent('Alma for Sylius', AlmaSyliusPaymentPlugin::VERSION);
        } catch (Exception $e) {
            $logger->error('[Alma] Error creating Alma API client: ' . $e->getMessage());
        }

        return $alma;
    }

    function getMerchantInfo(): ?Merchant
    {
        $client = $this->getDefaultClient();
        if (!$client) {
            return null;
        }

        /** @var Merchant|null $merchant */
        $merchant = null;

        try {
            $merchant = $client->merchants->me();
        } catch (RequestError $e) {
            $this->logger->error('[Alma] Error fetching merchant info: ' . $e->getMessage());
        }

        return $merchant;
    }

    /**
     * @return GatewayConfig
     */
    public function getGatewayConfig(): GatewayConfig
    {
        return $this->gatewayConfig;
    }
}
