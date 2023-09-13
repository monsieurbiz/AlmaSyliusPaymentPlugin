<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Controller;

use Payum\Core\Request\Capture;
use Sylius\Component\Core\Model\OrderInterface;
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

    public function __construct(
        Environment $twig,
        OrderRepositoryInterface $orderRepository,
    ) {
        $this->twig = $twig;
        $this->orderRepository = $orderRepository;
    }
    public function renderInstallmentPlanAction(Request $request): Response
    {
        try {
            $orderId = $request->attributes->getInt('orderId');
            /** @var OrderInterface $order */
            $order = $this->orderRepository->find($orderId);


            return new Response($this->twig->render('@AlmaSyliusPaymentPlugin/installmentPlan.html.twig', [
                'toto' => $order->getTotal(),
            ]));
        } catch (\InvalidArgumentException $exception) {
            return new Response('');
        }
    }
}
