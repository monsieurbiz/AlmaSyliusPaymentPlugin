<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfigInterface;
use Alma\SyliusPaymentPlugin\Payum\Request\RedirectToPaymentPage;
use Alma\SyliusPaymentPlugin\Payum\Request\RenderInPagePayment;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Capture;
use RuntimeException;

final class CaptureAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /**
     * @var AlmaBridgeInterface
     */
    protected $api;

    public function __construct()
    {
        $this->apiClass = AlmaBridge::class;
    }

    /**
     * @param Capture $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $config = $this->api->getGatewayConfig();

        $paymentPageMode = $config->getPaymentPageMode();
        switch ($paymentPageMode) {
            case GatewayConfigInterface::PAYMENT_PAGE_MODE_IN_PAGE:
                $this->gateway->execute(new RenderInPagePayment($request->getModel()));
                break;
            case GatewayConfigInterface::PAYMENT_PAGE_MODE_REDIRECT:
                $this->gateway->execute(new RedirectToPaymentPage($request));
                break;
            default:
                throw new RuntimeException(\sprintf('[Alma] Unknown payment page mode "%s". Check gateway config', $paymentPageMode));
        }
    }

    public function supports($request): bool
    {
        return
            $request instanceof Capture
            && $request->getModel() instanceof ArrayObject;
    }
}
