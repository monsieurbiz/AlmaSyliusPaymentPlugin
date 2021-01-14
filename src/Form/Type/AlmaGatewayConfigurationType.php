<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Form\Type;

use Alma\API\Client;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class AlmaGatewayConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('api_key', TextType::class)
            ->add('api_key_test', TextType::class)
            ->add(
                'api_mode',
                ChoiceType::class,
                [
                    'choices' => [
                        'Live' => Client::LIVE_MODE,
                        'Test' => Client::TEST_MODE,
                    ]
                ]
            )
            ->add(
                'api_pnx',
                ChoiceType::class,
                [
                    'choices' => [
                        'p2x' => 2,
                        'p3x' => 3,
                        'p4x' => 4,
                    ]
                ]
            );
    }
}
