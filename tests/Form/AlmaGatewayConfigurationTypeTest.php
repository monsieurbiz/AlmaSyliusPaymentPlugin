<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\Form;

use Alma\API\Client;
use Alma\API\Entities\Merchant;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Form\Type\AlmaGatewayConfigurationType;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfigInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;

final class AlmaGatewayConfigurationTypeTest extends TestCase
{
    public function testExistingInstallmentChoicesAndTwentyFourRemainSeparateAndSubmitAsIntegers(): void
    {
        foreach ([2, 3, 4, 10, 12, 24] as $count) {
            $bridge = $this->createMock(AlmaBridgeInterface::class);
            $merchant = new Merchant(['id' => 'merchant_fixture_' . $count]);
            $bridge->expects(self::once())->method('initialize')->with(self::callback(
                static fn (ArrayObject $config): bool => Client::TEST_MODE === $config['api_mode']
                    && 'test_key_fixture' === $config['test_api_key']
                    && (string) $count === $config['installments_count']
            ));
            $bridge->expects(self::once())->method('getMerchantInfo')->willReturn($merchant);
            $form = $this->form($bridge);
            self::assertSame([2, 3, 4, 10, 12, 24], array_values($form->get('installments_count')->getConfig()->getOption('choices')));
            $form->submit($this->submission($count));

            self::assertTrue($form->isValid(), (string) $form->getErrors(true));
            self::assertSame($count, $form->getData()['installments_count']);
            self::assertSame($merchant->id, $form->getData()['merchant_id']);
        }
    }

    public function testUnsupportedInstallmentCountsAreRejected(): void
    {
        foreach ([0, 1, 5, 6, 23, 25, -24, '24.5', 'invalid'] as $count) {
            $bridge = $this->createMock(AlmaBridgeInterface::class);
            $bridge->method('getMerchantInfo')->willReturn(new Merchant(['id' => 'merchant_fixture']));
            $form = $this->form($bridge);
            $form->submit($this->submission($count));

            self::assertFalse($form->isValid(), 'Unexpectedly accepted installment count: ' . $count);
            self::assertGreaterThan(0, count($form->get('installments_count')->getErrors(true)));
        }
    }

    public function testOnlyActiveModeKeyIsRequiredAndMerchantIsRefreshed(): void
    {
        foreach ([Client::TEST_MODE, Client::LIVE_MODE] as $mode) {
            $activeKey = Client::LIVE_MODE === $mode ? 'live_api_key' : 'test_api_key';
            $bridge = $this->createMock(AlmaBridgeInterface::class);
            $bridge->expects(self::once())->method('initialize')->with(self::callback(
                static fn (ArrayObject $config): bool => $mode === $config['api_mode'] && 'active_key_fixture' === $config[$activeKey]
            ));
            $bridge->expects(self::once())->method('getMerchantInfo')->willReturn(new Merchant(['id' => 'verified_merchant_fixture']));
            $form = $this->form($bridge);
            $data = $this->submission(24);
            $data['api_mode'] = $mode;
            $data['test_api_key'] = '';
            $data['live_api_key'] = '';
            $data[$activeKey] = 'active_key_fixture';
            $data['merchant_id'] = 'unverified_merchant_fixture';
            $form->submit($data);

            self::assertTrue($form->isValid(), (string) $form->getErrors(true));
            self::assertSame('verified_merchant_fixture', $form->getData()['merchant_id']);
        }
    }

    public function testBlankActiveKeyIsRejectedWithoutCallingMerchantApi(): void
    {
        foreach ([Client::TEST_MODE, Client::LIVE_MODE] as $mode) {
            $bridge = $this->createMock(AlmaBridgeInterface::class);
            $bridge->expects(self::never())->method('initialize');
            $bridge->expects(self::never())->method('getMerchantInfo');
            $form = $this->form($bridge);
            $data = $this->submission(24);
            $data['api_mode'] = $mode;
            $activeKey = Client::LIVE_MODE === $mode ? 'live_api_key' : 'test_api_key';
            $data[$activeKey] = '   ';
            $form->submit($data);

            self::assertFalse($form->isValid());
            self::assertSame('alma_sylius_payment_plugin.errors.empty_api_key', $form->get($activeKey)->getErrors()[0]->getMessage());
        }
    }

    public function testUnknownMerchantRejectsActiveKeyAndErrorsDoNotLeakToNextSubmission(): void
    {
        foreach ([Client::TEST_MODE, Client::LIVE_MODE] as $mode) {
            $bridge = $this->createMock(AlmaBridgeInterface::class);
            $bridge->expects(self::exactly(2))->method('initialize');
            $bridge->expects(self::exactly(2))->method('getMerchantInfo')->willReturnOnConsecutiveCalls(null, new Merchant(['id' => 'merchant_fixture']));
            $factory = $this->factory($bridge);
            $data = $this->submission(24);
            $data['api_mode'] = $mode;
            $form = $factory->create(AlmaGatewayConfigurationType::class, []);
            $form->submit($data);

            $activeKey = Client::LIVE_MODE === $mode ? 'live_api_key' : 'test_api_key';
            self::assertFalse($form->isValid());
            self::assertSame('alma_sylius_payment_plugin.errors.invalid_api_key', $form->get($activeKey)->getErrors()[0]->getMessage());

            $nextForm = $factory->create(AlmaGatewayConfigurationType::class, []);
            $nextForm->submit($data);
            self::assertTrue($nextForm->isValid(), (string) $nextForm->getErrors(true));
        }
    }

    private function form(AlmaBridgeInterface $bridge): FormInterface
    {
        return $this->factory($bridge)->create(AlmaGatewayConfigurationType::class, []);
    }

    private function factory(AlmaBridgeInterface $bridge): \Symfony\Component\Form\FormFactoryInterface
    {
        $translator = new Translator('fr');
        $translator->addLoader('array', new \Symfony\Component\Translation\Loader\ArrayLoader());
        $translator->addResource('array', [
            'alma_sylius_payment_plugin.config.installments_count_choice_label' => '%installments_count% échéances',
        ], 'fr');

        return Forms::createFormFactoryBuilder()
            ->addType(new AlmaGatewayConfigurationType($translator, $bridge))
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }

    private function submission($count): array
    {
        return [
            'api_mode' => Client::TEST_MODE,
            'test_api_key' => 'test_key_fixture',
            'live_api_key' => 'live_key_fixture',
            'installments_count' => (string) $count,
            'payment_page_mode' => GatewayConfigInterface::PAYMENT_PAGE_MODE_REDIRECT,
            'merchant_id' => '',
        ];
    }
}
