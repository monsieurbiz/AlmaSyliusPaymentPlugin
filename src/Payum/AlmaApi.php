<?php


declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum;

use Alma\API\Client;
use Payum\Core\Bridge\Spl\ArrayObject;

final class AlmaApi
{
    /**
     * @var ArrayObject
     */
    private $config;

    /**
     * Constructor
     *
     * @param ArrayObject $config Array of configuration for Alma API
     */
    public function __construct(ArrayObject $config)
    {

        $this->config = $config;
    }

    /**
     * Get Api key
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return (string) $this->config['api_key'];
    }

    /**
     * Get Api mode (test or live)
     *
     * @return string
     */
    public function getApiMode(): string
    {
        return (string) $this->config['api_mode'];
    }

    /**
     * Get Alma API url depends on Api mode
     *
     * @return string
     */
    public function getApiurl(): string
    {
        return $this->getApiMode() == "live"
            ? Client::LIVE_API_URL
            : Client::SANDBOX_API_URL;
    }

    /**
     * Get Installment plan
     *
     * @return integer
     */
    public function getPnx(): int
    {
        return (int) $this->config['api_pnx'];
    }
}
