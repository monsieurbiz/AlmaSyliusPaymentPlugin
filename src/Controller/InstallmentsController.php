<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class InstallmentsController
{
    /**
     * @var Environment
     */
    private  $twig;

    public function __construct(
        Environment $twig
    ) {
        $this->twig = $twig;
    }
    public function renderInstallmentPlanAction(Request $request): Response
    {
        try {
            return new Response($this->twig->render('@AlmaSyliusPaymentPlugin/installmentPlan.html.twig', [
                'toto' => 'VICTOIRE',
            ]));
        } catch (\InvalidArgumentException $exception) {
            return new Response('');
        }
    }
}
