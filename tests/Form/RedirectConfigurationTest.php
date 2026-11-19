<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\Form;

use Alma\API\Client;
use Alma\API\Entities\Merchant;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Form\Type\AlmaGatewayConfigurationType;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfigInterface as Config;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\Translation\Translator;

final class RedirectConfigurationTest extends TestCase
{
    public function testNewAndHistoricalConfigurationsOpenAndSaveAsRedirect(): void
    {
        foreach ([null, Config::PAYMENT_PAGE_MODE_IN_PAGE, Config::PAYMENT_PAGE_MODE_REDIRECT] as $mode) {
            $bridge = $this->createMock(AlmaBridgeInterface::class);
            $bridge->method('getMerchantInfo')->willReturn(new Merchant(['id' => 'merchant_fixture']));
            $factory = Forms::createFormFactoryBuilder()
                ->addType(new AlmaGatewayConfigurationType(new Translator('en'), $bridge))
                ->getFormFactory();
            $data = [
                'live_api_key' => '',
                'test_api_key' => 'test_key_fixture',
                'api_mode' => Client::TEST_MODE,
                'installments_count' => 24,
            ];
            if (null !== $mode) {
                $data[Config::CONFIG_PAYMENT_PAGE_MODE] = $mode;
            }
            $form = $factory->create(AlmaGatewayConfigurationType::class, $data);
            self::assertSame(Config::PAYMENT_PAGE_MODE_REDIRECT, $form->getData()[Config::CONFIG_PAYMENT_PAGE_MODE]);
            self::assertSame([Config::PAYMENT_PAGE_MODE_REDIRECT], array_values($form->get(Config::CONFIG_PAYMENT_PAGE_MODE)->getConfig()->getOption('choices')));

            // Submit the original legacy value, not the already normalized form data.
            $form->submit($data);
            self::assertTrue($form->isValid(), (string) $form->getErrors(true));
            self::assertSame(Config::PAYMENT_PAGE_MODE_REDIRECT, $form->getData()[Config::CONFIG_PAYMENT_PAGE_MODE]);
            self::assertSame(24, $form->getData()['installments_count']);
        }
    }

    public function testLegacyModeIsNormalizedEvenWhenApiKeyValidationFails(): void
    {
        $bridge = $this->createMock(AlmaBridgeInterface::class);
        $bridge->expects(self::never())->method('initialize');
        $form = Forms::createFormFactoryBuilder()
            ->addType(new AlmaGatewayConfigurationType(new Translator('en'), $bridge))
            ->getFormFactory()->create(AlmaGatewayConfigurationType::class);
        $form->submit([
            'api_mode' => Client::TEST_MODE,
            'test_api_key' => '',
            'installments_count' => '3',
            Config::CONFIG_PAYMENT_PAGE_MODE => Config::PAYMENT_PAGE_MODE_IN_PAGE,
        ]);

        self::assertFalse($form->isValid());
        self::assertTrue($form->get(Config::CONFIG_PAYMENT_PAGE_MODE)->isSynchronized());
        self::assertSame(Config::PAYMENT_PAGE_MODE_REDIRECT, $form->getData()[Config::CONFIG_PAYMENT_PAGE_MODE]);
    }
}
