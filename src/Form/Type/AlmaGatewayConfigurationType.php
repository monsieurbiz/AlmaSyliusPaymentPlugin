<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Form\Type;

use Alma\API\Client as AlmaClient;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfigInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webmozart\Assert\Assert;


final class AlmaGatewayConfigurationType extends AbstractType
{
    /** @var TranslatorInterface */
    private $translator;
    /**
     * @var AlmaBridgeInterface
     */
    private $almaBridge;
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        TranslatorInterface $translator,
        AlmaBridgeInterface $almaBridge,
        LoggerInterface $logger
    ) {
        $this->translator = $translator;
        $this->almaBridge = $almaBridge;
        $this->logger = $logger;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(GatewayConfigInterface::CONFIG_LIVE_API_KEY, TextType::class, [
                'label' => 'alma_sylius_payment_plugin.ui.live_api_key_label',
                'help' => $this->translator->trans('alma_sylius_payment_plugin.ui.find_api_keys_in_dashboard'),
                'help_html' => true,
            ])
            ->add(GatewayConfigInterface::CONFIG_TEST_API_KEY, TextType::class, [
                'label' => 'alma_sylius_payment_plugin.ui.test_api_key_label',
                'help' => $this->translator->trans('alma_sylius_payment_plugin.ui.find_api_keys_in_dashboard'),
                'help_html' => true,
            ])
            ->add(
                GatewayConfigInterface::CONFIG_API_MODE,
                ChoiceType::class,
                [
                    'choices' => [
                        'Live' => AlmaClient::LIVE_MODE,
                        'Test' => AlmaClient::TEST_MODE,
                    ],
                    'label' => $this->translator->trans('alma_sylius_payment_plugin.ui.api_mode_label'),
                    'help' => $this->translator->trans('alma_sylius_payment_plugin.ui.api_mode_tip'),
                ]
            )
            ->add(
                GatewayConfigInterface::CONFIG_INSTALLMENTS_COUNT,
                ChoiceType::class,
                [
                    'choices' => [
                        $this->translator->trans('alma_sylius_payment_plugin.ui.installments_count_choice_label',
                            ['installments_count' => 2]) => 2,
                        $this->translator->trans('alma_sylius_payment_plugin.ui.installments_count_choice_label',
                            ['installments_count' => 3]) => 3,
                        $this->translator->trans('alma_sylius_payment_plugin.ui.installments_count_choice_label',
                            ['installments_count' => 4]) => 4,
                    ],
                    'label' => $this->translator->trans('alma_sylius_payment_plugin.ui.installments_count_label'),
                ]
            );

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $data = ArrayObject::ensureArrayObject($event->getData());
            $this->almaBridge->initialize($data);
            $config = $this->almaBridge->getGatewayConfig();

            $apiKey = $config->getActiveApiKey();
            Assert::notEmpty($apiKey);

            $merchant = $this->almaBridge->getMerchantInfo();

            if ($merchant == null) {
                $apiKeyConfigKey = $config->getApiMode() === AlmaClient::LIVE_MODE
                    ? GatewayConfigInterface::CONFIG_LIVE_API_KEY
                    : GatewayConfigInterface::CONFIG_TEST_API_KEY;

                $event->getForm()->get($apiKeyConfigKey)->addError(new FormError(
                    $this->translator->trans('alma_sylius_payment_plugin.errors.invalid_api_key', [], 'validators')
                ));
            } else {
                $updatedData = $data->getArrayCopy();
                $updatedData[GatewayConfigInterface::CONFIG_MERCHANT_ID] = $merchant->id;
                $event->setData($updatedData);
            }
        });
    }
}
