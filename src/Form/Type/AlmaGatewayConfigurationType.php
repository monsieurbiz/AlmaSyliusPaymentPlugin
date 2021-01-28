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
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
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

    /**
     * @var array<string, string>
     */
    private $errors;

    public function __construct(
        TranslatorInterface $translator,
        AlmaBridgeInterface $almaBridge,
        LoggerInterface $logger
    ) {
        $this->translator = $translator;
        $this->almaBridge = $almaBridge;
        $this->logger = $logger;

        $this->errors = [];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(GatewayConfigInterface::CONFIG_LIVE_API_KEY, TextType::class, [
                'label' => 'alma_sylius_payment_plugin.config.live_api_key_label',
                'help' => $this->translator->trans('alma_sylius_payment_plugin.config.find_api_keys_in_dashboard'),
                'help_html' => true,
            ])
            ->add(GatewayConfigInterface::CONFIG_TEST_API_KEY, TextType::class, [
                'label' => 'alma_sylius_payment_plugin.config.test_api_key_label',
                'help' => $this->translator->trans('alma_sylius_payment_plugin.config.find_api_keys_in_dashboard'),
                'help_html' => true,
            ])
            ->add(
                GatewayConfigInterface::CONFIG_API_MODE,
                ChoiceType::class,
                [
                    'choices' => [
                        $this->translator->trans('alma_sylius_payment_plugin.api.live_mode') => AlmaClient::LIVE_MODE,
                        $this->translator->trans('alma_sylius_payment_plugin.api.test_mode') => AlmaClient::TEST_MODE,
                    ],
                    'label' => $this->translator->trans('alma_sylius_payment_plugin.config.api_mode_label'),
                    'help' => $this->translator->trans('alma_sylius_payment_plugin.config.api_mode_tip'),
                ]
            )
            ->add(
                GatewayConfigInterface::CONFIG_INSTALLMENTS_COUNT,
                ChoiceType::class,
                [
                    'choices' => [
                        $this->translator->trans('alma_sylius_payment_plugin.config.installments_count_choice_label',
                            ['%installments_count%' => 2]) => 2,
                        $this->translator->trans('alma_sylius_payment_plugin.config.installments_count_choice_label',
                            ['%installments_count%' => 3]) => 3,
                        $this->translator->trans('alma_sylius_payment_plugin.config.installments_count_choice_label',
                            ['%installments_count%' => 4]) => 4,
                    ],
                    'label' => $this->translator->trans('alma_sylius_payment_plugin.config.installments_count_label'),
                ]
            )
            ->add(GatewayConfigInterface::CONFIG_MERCHANT_ID, HiddenType::class);

        $builder
            ->addEventListener(FormEvents::PRE_SUBMIT, [$this, 'onPreSubmit'])
            ->addEventListener(FormEvents::POST_SUBMIT, [$this, 'onPostSubmit']);
    }

    public function onPreSubmit(FormEvent $event): void
    {
        $this->errors = [];
        $data = ArrayObject::ensureArrayObject($event->getData());

        // Only check the API key for the mode that's been activated by the merchant
        $apiKeyConfigKey = $data[GatewayConfigInterface::CONFIG_API_MODE] === AlmaClient::LIVE_MODE
            ? GatewayConfigInterface::CONFIG_LIVE_API_KEY
            : GatewayConfigInterface::CONFIG_TEST_API_KEY;

        /** @var string $apiKey */
        $apiKey = $data[GatewayConfigInterface::CONFIG_API_MODE] === AlmaClient::LIVE_MODE
            ? $data[GatewayConfigInterface::CONFIG_LIVE_API_KEY]
            : $data[GatewayConfigInterface::CONFIG_TEST_API_KEY];;

        if ($apiKey == null || trim($apiKey) == "") {
            $this->errors[$apiKeyConfigKey] = 'alma_sylius_payment_plugin.errors.empty_api_key';

            return;
        }

        // At this point we know $data contains an API key we can try to connect with
        $this->almaBridge->initialize($data);
        $merchant = $this->almaBridge->getMerchantInfo();
        if ($merchant === null) {
            $this->errors[$apiKeyConfigKey] = 'alma_sylius_payment_plugin.errors.invalid_api_key';
        } else {
            $data[GatewayConfigInterface::CONFIG_MERCHANT_ID] = $merchant->id;
            $event->setData($data->getArrayCopy());
        }
    }

    public function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();

        foreach ($this->errors as $field => $error) {
            $form->get($field)->addError(new FormError($this->translator->trans($error)));
        }
    }
}
