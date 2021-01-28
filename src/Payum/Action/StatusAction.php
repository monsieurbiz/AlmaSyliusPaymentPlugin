<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use Alma\API\Entities\Payment;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\GetStatusInterface;
use Sylius\Component\Core\Model\PaymentInterface;


final class StatusAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /** @var AlmaBridge */
    protected $api;

    public function __construct()
    {
        $this->apiClass = AlmaBridge::class;
    }

    /**
     * @param GetStatusInterface $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();
        $details = $payment->getDetails();

        $httpRequest = new GetHttpRequest();
        $this->gateway->execute($httpRequest);

        if (!isset($details['payload'])) {
            $request->markNew();

            return;
        } elseif (!isset($httpRequest->query['pid'])) {
            $request->markPending();

            return;
        }

        if (isset($httpRequest->query['pid'])) {
            $details['payment_id'] = (string)$httpRequest->query['pid'];
            $payment->setDetails($details);

            $this->handlePaymentState($request, $details['payment_id']);
        } else {
            $request->markUnknown();
        }

    }

    public function supports($request): bool
    {
        return
            $request instanceof GetStatusInterface &&
            $request->getModel() instanceof PaymentInterface;
    }

    private function handlePaymentState(GetStatusInterface $request, string $paymentId): void
    {
        $almaClient = $this->api->getDefaultClient();
        $almaPayment = $almaClient->payments->fetch($paymentId);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();

        if (
            $almaPayment->purchase_amount === $payment->getAmount() &&
            ($almaPayment->state === Payment::STATE_IN_PROGRESS || $almaPayment->state === Payment::STATE_PAID)
        ) {
            $request->markCaptured();
        } else {
            $request->markFailed();
        }
    }
}
