<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum;

use Payum\Core\Bridge\Spl\ArrayObject;

final class AlmaApi
{
    /**
     * @var ArrayObject
     */
    private $config;

    public function __construct(ArrayObject $config)
    {

        $this->config = $config;
    }

    public function getApiKey(): string
    {
        return (string) $this->config['api_key'];
    }

    public function getApiMode(): string
    {
        return (string) $this->config['api_mode'];
    }
}
