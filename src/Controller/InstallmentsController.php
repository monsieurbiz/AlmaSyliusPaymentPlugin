<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Controller;

use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Helper\EligibilityHelper;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\PaymentMethodRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
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
     * @var EligibilityHelper
     */
    private $eligibilityHelper;

    /**
     * @param Environment $twig
     * @param OrderRepositoryInterface $orderRepository
     * @param PaymentMethodRepository $paymentMethodRepository
     * @param EligibilityHelper $eligibilityHelper
     */
    public function __construct(
        Environment $twig,
        OrderRepositoryInterface $orderRepository,
        PaymentMethodRepository $paymentMethodRepository,
        EligibilityHelper $eligibilityHelper
    ) {
        $this->twig = $twig;
        $this->orderRepository = $orderRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->eligibilityHelper = $eligibilityHelper;
    }

    /**
     * @param Request $request
     * @return Response
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function renderInstallmentPlanAction(Request $request): Response
    {
        try {
            $orderId = $request->attributes->getInt('orderId');
            $methodId = $request->attributes->getInt('methodPaymentId');

            /** @var OrderInterface $order */
            $order = $this->orderRepository->find($orderId);

            /** @var PaymentMethodInterface $paymentMethod */
            $paymentMethod = $this->paymentMethodRepository->find($methodId);

            $eligibilities = $this->eligibilityHelper->getEligibilities(
                $order->getTotal(),
                $paymentMethod->getGatewayConfig()->getConfig()["installments_count"],
                $order->getBillingAddress()->getCountryCode(),
                $order->getShippingAddress()->getCountryCode(),
                substr($request->getLocale(), 0, 2)
            );

            return new Response($this->twig->render('@AlmaSyliusPaymentPlugin/installmentPlan.html.twig', [
                'eligibilities' => $eligibilities
            ]));
        } catch (\InvalidArgumentException $exception) {
            return new Response('');
        }
    }
}
