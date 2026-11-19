<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Payum\Request\RedirectToPaymentPage;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Capture;
use Sylius\Component\Core\Model\PaymentInterface;

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

        $this->gateway->execute(new RedirectToPaymentPage($request));
    }

    public function supports($request): bool
    {
        if (!$request instanceof Capture) {
            return false;
        }

        $model = $request->getModel();

        // Reopening uses the stored PID, not the status check that Sylius uses before conversion.
        return $model instanceof ArrayObject
            || ($model instanceof PaymentInterface
                && array_key_exists(AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID, $model->getDetails()));
    }
}
