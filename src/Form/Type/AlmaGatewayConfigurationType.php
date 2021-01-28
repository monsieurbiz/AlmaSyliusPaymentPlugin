<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Form\Type;

use Alma\API\Client;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfigInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class AlmaGatewayConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(GatewayConfigInterface::CONFIG_LIVE_API_KEY, TextType::class)
            ->add(GatewayConfigInterface::CONFIG_TEST_API_KEY, TextType::class)
            ->add(
                GatewayConfigInterface::CONFIG_API_MODE,
                ChoiceType::class,
                [
                    'choices' => [
                        'Live' => Client::LIVE_MODE,
                        'Test' => Client::TEST_MODE,
                    ]
                ]
            )
            ->add(
                GatewayConfigInterface::CONFIG_INSTALLMENTS_COUNT,
                ChoiceType::class,
                [
                    'choices' => [
                        '2 installments' => 2,
                        '3 installments' => 3,
                        '4 installments' => 4,
                    ]
                ]
            );
    }
}
