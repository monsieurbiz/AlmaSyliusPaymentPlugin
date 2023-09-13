<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Controller;

use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\PaymentMethodRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\PayPalPlugin\Processor\LocaleProcessorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class InstallmentsController
{
    /**
     * @var Environment
     */
    private  $twig;

    /**
     * @var OrderRepositoryInterface
     */
    private  $orderRepository;

    /**
     * @var PaymentMethodRepository
     */
    private $paymentMethodRepository;

    /**
     * @var AlmaBridgeInterface
     */
    private $almaBridge;

    /**
     * @var LocaleContextInterface
     */
    private $localeContext;

    /**
     * @var LocaleProcessorInterface
     */
    private $localeProcessor;


    public function __construct(
        Environment $twig,
        OrderRepositoryInterface $orderRepository,
        PaymentMethodRepository $paymentMethodRepository,
        AlmaBridgeInterface $almaBridge,
        LocaleContextInterface $localeContext,
        LocaleProcessorInterface $localeProcessor
    ) {
        $this->twig = $twig;
        $this->orderRepository = $orderRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->almaBridge = $almaBridge;
        $this->localeContext = $localeContext;
        $this->localeProcessor = $localeProcessor;
    }

    public function renderInstallmentPlanAction(Request $request): Response
    {
        try {
            $orderId = $request->attributes->getInt('orderId');
            $methodId = $request->attributes->getInt('methodPaymentId');

            /** @var OrderInterface $order */
            $order = $this->orderRepository->find($orderId);
            /** @var PaymentMethodInterface $paymentMethod */
            $paymentMethod = $this->paymentMethodRepository->find($methodId);

            $installmentCounts = $paymentMethod->getGatewayConfig()->getConfig()["installments_count"];
var_dump( $this->localeProcessor->process($this->localeContext->getLocaleCode()));die;
            $data = [
                "purchase_amount" => $order->getTotal(),
                "queries" => [
                    [
                        "installments_count" =>  $installmentCounts,
                        "deferred_days" =>  "0",
                        "deferred_months" =>  "0"
                    ]
                ],
                "billing_address" => [
                    "country" => $order->getBillingAddress()->getCountryCode(),
                ],
                "shipping_address" => [
                    "country" => $order->getShippingAddress()->getCountryCode()
                ],
                "locale" => $this->localeContext->getLocaleCode(),
            ];

            $eligibilities = $this->almaBridge->retrieveEligibilities($data);

            return new Response($this->twig->render('@AlmaSyliusPaymentPlugin/installmentPlan.html.twig', [
                'eligibilities' => $eligibilities
            ]));
        } catch (\InvalidArgumentException $exception) {
            return new Response('');
        }
    }
}
