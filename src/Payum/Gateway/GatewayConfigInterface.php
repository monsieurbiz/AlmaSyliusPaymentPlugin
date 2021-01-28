<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Gateway;

interface GatewayConfigInterface {
    const CONFIG_LIVE_API_KEY = 'live_api_key';
    const CONFIG_TEST_API_KEY = 'test_api_key';
    const CONFIG_API_MODE = 'api_mode';
    const CONFIG_INSTALLMENTS_COUNT = 'installments_count';
    const CONFIG_PAYMENT_FORM_TEMPLATE = 'payum.template.payment_form_template';

    function getApiMode(): string;

    function getActiveApiKey(): string;
    function getLiveApiKey(): string;
    function getTestApiKey(): string;

    function getInstallmentsCount(): int;

    function getPaymentFormTemplate(): string;
}
