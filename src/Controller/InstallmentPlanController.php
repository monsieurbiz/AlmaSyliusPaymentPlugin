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
        $orderId = $request->attributes->getInt('orderId');
        /** @var ChannelInterface $channel */
        $channel = $this->channelContext->getChannel();
        /** @var OrderInterface $order */
        $order = $this->orderRepository->find($orderId);

        try {
            return new Response($this->twig->render('@SyliusPayPalPlugin/payFromPaymentPage.html.twig', [
                'available_countries' => $this->availableCountriesProvider->provide(),
                'cancelPayPalPaymentUrl' => $this->router->generate('sylius_paypal_plugin_cancel_payment'),
                'clientId' => $this->payPalConfigurationProvider->getClientId($channel),
                'currency' => $order->getCurrencyCode(),
                'completePayPalOrderFromPaymentPageUrl' => $this->router->generate('sylius_paypal_plugin_complete_paypal_order_from_payment_page', ['id' => $orderId]),
                'createPayPalOrderFromPaymentPageUrl' => $this->router->generate('sylius_paypal_plugin_create_paypal_order_from_payment_page', ['id' => $orderId]),
                'errorPayPalPaymentUrl' => $this->router->generate('sylius_paypal_plugin_payment_error'),
                'locale' => $this->localeProcessor->process((string) $order->getLocaleCode()),
                'orderId' => $orderId,
                'partnerAttributionId' => $this->payPalConfigurationProvider->getPartnerAttributionId($channel),
            ]));
        } catch (\InvalidArgumentException $exception) {
            return new Response('');
        }
    }
}
