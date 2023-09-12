<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class InstallmentsController extends AbstractController
{

    public function renderInstallmentPlanAction(): Response
    {
        try {
            return $this->render('@AlmaSyliusPaymentPlugin/installmentPlan.html.twig', [
                'toto' => 'VICTOIREEEE',
            ]);
        } catch (\InvalidArgumentException $exception) {
            return new Response('');
        }
    }
}
