<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Bridge;



use Alma\API\Client;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfig;
use Exception;
use Payum\Core\Bridge\Spl\ArrayObject;
use Psr\Log\LoggerInterface;

class AlmaBridge implements AlmaBridgeInterface
{
    /**
     * @var GatewayConfig
     */
    private $gatewayConfig = null;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function initialize(ArrayObject $config): void
    {
        $this->gatewayConfig = new GatewayConfig($config);
    }

    public function getDefaultClient(?string $mode = null): Client
    {
        static $_almaClient;

        if ($mode === null) {
            $mode = $this->gatewayConfig->getApiMode();
        }

        if (!$_almaClient) {
            $_almaClient = self::createClientInstance(
                $this->gatewayConfig->getActiveApiKey(),
                $mode,
                $this->logger
            );
        }

        return $_almaClient;
    }

    public static function createClientInstance(string $apiKey, string $mode, LoggerInterface $logger): ?Client
    {
        $alma = null;

        try {
            $alma = new Client($apiKey, [
                'mode' => $mode,
                'logger' => $logger
            ]);

            // TODO: add versions
            $alma->addUserAgentComponent('Sylius', '0');
            $alma->addUserAgentComponent('Alma for Sylius', '0');
        } catch (Exception $e) {
            $logger->error('Error creating Alma API client: ' . $e->getMessage());
        }

        return $alma;
    }

    /**
     * @return GatewayConfig
     */
    public function getGatewayConfig(): GatewayConfig
    {
        return $this->gatewayConfig;
    }
}
