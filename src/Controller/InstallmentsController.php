<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Controller;

use Alma\API\Entities\FeePlan;
use Alma\SyliusPaymentPlugin\Helper\EligibilityHelper;
use Psr\Log\LoggerInterface;
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
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Environment $twig
     * @param OrderRepositoryInterface $orderRepository
     * @param PaymentMethodRepository $paymentMethodRepository
     * @param EligibilityHelper $eligibilityHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        Environment $twig,
        OrderRepositoryInterface $orderRepository,
        PaymentMethodRepository $paymentMethodRepository,
        EligibilityHelper $eligibilityHelper,
        LoggerInterface $logger
    ) {
        $this->twig = $twig;
        $this->orderRepository = $orderRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->eligibilityHelper = $eligibilityHelper;
        $this->logger = $logger;
    }

    /**
     * @param Request $request
     * @return Response
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
            $installmentsCount = $paymentMethod->getGatewayConfig()->getConfig()["installments_count"];
            $totalCart = $order->getTotal();

            $eligibilities = $this->eligibilityHelper->getEligibilities(
                $totalCart,
                $installmentsCount,
                $order->getBillingAddress()->getCountryCode(),
                $order->getShippingAddress()->getCountryCode(),
                substr($request->getLocale(), 0, 2)
            );
            /**
             * @var FeePlan $plan
             */
            $plan = $eligibilities['general_'.$installmentsCount.'_0_0'];

            return new Response($this->twig->render('@AlmaSyliusPaymentPlugin/installmentPlan.html.twig', [
                'plans' => $plan,
                'installmentsCount' => $installmentsCount,
                'creditInfo' => [
                    'totalCart' => $totalCart,
                    'costCredit' => $plan->customerTotalCostAmount,
                    'totalCredit' => $plan->customerTotalCostAmount + $totalCart,
                    'taeg' => $plan->annualInterestRate,
                ]
            ]));
        } catch (\Exception $exception) {
            $this->logger->error(
                sprintf(
                    '[Alma] An error as occurred %s',
                    $exception->getMessage()
                ), $exception->getTrace()
            );

            return new Response('');
        }
    }
}
