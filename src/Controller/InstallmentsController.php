<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Controller;

use Alma\API\Entities\FeePlan;
use Alma\SyliusPaymentPlugin\Helper\EligibilityHelper;
use Exception;
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
    public function __construct(
        private Environment $twig,
        private OrderRepositoryInterface $orderRepository,
        private PaymentMethodRepository $paymentMethodRepository,
        private EligibilityHelper $eligibilityHelper,
        private LoggerInterface $logger,
    ) {
    }

    public function renderInstallmentPlanAction(Request $request): Response
    {
        try {
            $orderId = $request->attributes->getInt('orderId');
            $methodId = $request->attributes->getInt('methodPaymentId');

            /** @var ?OrderInterface $order */
            $order = $this->orderRepository->find($orderId);
            if (null === $order) {
                throw new Exception('Order cannot be found.');
            }

            /** @var ?PaymentMethodInterface $paymentMethod */
            $paymentMethod = $this->paymentMethodRepository->find($methodId);
            if (null === $paymentMethod) {
                throw new Exception('Payment method cannot be found.');
            }

            $paymentGatewayConfig = $paymentMethod->getGatewayConfig();
            if (null === $paymentGatewayConfig) {
                throw new Exception('Payment gateway config cannot be found.');
            }

            $installmentsCount = $paymentGatewayConfig->getConfig()['installments_count'];
            $totalCart = $order->getTotal();

            $this->eligibilityHelper->initializeConfig($paymentMethod);
            $eligibilities = $this->eligibilityHelper->getEligibilities(
                $totalCart,
                $installmentsCount,
                $order->getBillingAddress()?->getCountryCode() ?? '',
                $order->getShippingAddress()?->getCountryCode() ?? '',
                substr($request->getLocale(), 0, 2)
            );

            /** @var ?FeePlan $plan */
            $plan = $eligibilities['general_' . $installmentsCount . '_0_0'] ?? null;
            if (null === $plan) {
                throw new Exception('Fee plan cannot be found.');
            }

            return new Response($this->twig->render('@AlmaSyliusPaymentPlugin/installmentPlan.html.twig', [
                'plans' => $plan,
                'installmentsCount' => $installmentsCount,
                'creditInfo' => [
                    'totalCart' => $totalCart,
                    'costCredit' => $plan->customerTotalCostAmount,
                    'totalCredit' => $plan->customerTotalCostAmount + $totalCart,
                    'taeg' => $plan->annualInterestRate,
                ],
            ]));
        } catch (Exception $exception) {
            $this->logger->error(
                \sprintf(
                    '[Alma] An error as occurred %s',
                    $exception->getMessage()
                ), $exception->getTrace()
            );

            return new Response('');
        }
    }
}
