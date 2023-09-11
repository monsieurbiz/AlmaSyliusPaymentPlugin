<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Controller;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\PayPalPlugin\Processor\LocaleProcessorInterface;
use Sylius\PayPalPlugin\Provider\AvailableCountriesProviderInterface;
use Sylius\PayPalPlugin\Provider\PayPalConfigurationProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class InstallmentPlanController
{
    private Environment $twig;

    private UrlGeneratorInterface $router;

    private ChannelContextInterface $channelContext;

    private LocaleContextInterface $localeContext;

    private OrderRepositoryInterface $orderRepository;

    private AvailableCountriesProviderInterface $availableCountriesProvider;

    private LocaleProcessorInterface $localeProcessor;

    public function __construct(
        Environment $twig,
        UrlGeneratorInterface $router,
        ChannelContextInterface $channelContext,
        LocaleContextInterface $localeContext,
        OrderRepositoryInterface $orderRepository,
        AvailableCountriesProviderInterface $availableCountriesProvider,
        LocaleProcessorInterface $localeProcessor
    ) {
        $this->twig = $twig;
        $this->router = $router;
        $this->channelContext = $channelContext;
        $this->localeContext = $localeContext;
        $this->orderRepository = $orderRepository;
        $this->availableCountriesProvider = $availableCountriesProvider;
        $this->localeProcessor = $localeProcessor;
    }

    public function renderPaymentPageInstallmentPlanAction(Request $request): Response
    {
        try {
            return new Response($this->twig->render('@AlmaSyliusPaymentPlugin/installmentPlan.html.twig', [
                'toto' => 'toto',
            ]));
        } catch (\InvalidArgumentException $exception) {
            return new Response('');
        }
    }
}
