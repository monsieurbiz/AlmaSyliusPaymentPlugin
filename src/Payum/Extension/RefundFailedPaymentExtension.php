<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Extension;

use Alma\API\Entities\Payment;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Exception;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Extension\Context;
use Payum\Core\Extension\ExtensionInterface;
use Payum\Core\Request\GetStatusInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 *  A Payum extension to make sure all payment validation failures lead to a full refund of the payment, so that the
 *  customer isn't left with an ongoing payment but without a validated order.
 */
class RefundFailedPaymentExtension implements ExtensionInterface
{
    private ?SessionInterface $session;

    public function __construct(
        private AlmaBridgeInterface $api,
        private LoggerInterface $logger,
        RequestStack $requestStack,
    ) {
        $this->api = $api;
        $this->logger = $logger;
        $this->session = $requestStack->getMainRequest()?->getSession();
    }

    private function addFlash(string $type, string $message, ?array $params = []): void
    {
        if (null === $this->session) {
            return;
        }

        /** @var FlashBagInterface $flashBag */
        $flashBag = $this->session->getBag('flashes');

        // Avoid duplicate flashes
        /** @var array|string $flash */
        foreach ($flashBag->peek($type) as $flash) {
            if (\is_array($flash) && \array_key_exists('message', $flash) && $flash['message'] === $message) {
                return;
            }
        }

        $flashBag->add($type, ['message' => $message, 'parameters' => $params]);
    }

    public function onPostExecute(Context $context): void
    {
        $request = $context->getRequest();

        if (false === $request instanceof GetStatusInterface) {
            return;
        }

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();
        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        /** @var Payment|array|null $paymentData */
        $paymentData = $details->get(AlmaBridgeInterface::DETAILS_KEY_PAYMENT_DATA);
        if (\is_array($paymentData)) {
            $paymentData = new Payment($paymentData);
        }

        // Only check new/processing payments that have a `false` is_valid value (exact value; null/non-existing value
        // should return) in their details data, that haven't been already refunded because of a validation error and
        // of which associated Alma payment is in paid/ongoing state (otherwise it means it's been cancelled/expired,
        // or it's long past validation time and this could be a fraudulent refund attempt)
        if (
            !\in_array($payment->getState(), [PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING], true)
            || false !== $details->get(AlmaBridgeInterface::DETAILS_KEY_IS_VALID)
            || true === $details->get(AlmaBridgeInterface::DETAILS_KEY_ERROR_TRIGGERED_REFUND)
            || (null !== $paymentData
                && !\in_array($paymentData->state, [Payment::STATE_PAID, Payment::STATE_IN_PROGRESS], true))
        ) {
            return;
        }

        /** @var int $pid */
        $pid = $payment->getId();

        /** @var string $almaPid */
        $almaPid = $details[AlmaBridge::DETAILS_KEY_PAYMENT_ID];

        $alma = $this->api->getDefaultClient();

        try {
            $alma->payments->fullRefund($almaPid);
            $this->addFlash('info', 'alma_sylius_payment_plugin.payment.failed_refunded');

            $details[AlmaBridgeInterface::DETAILS_KEY_ERROR_TRIGGERED_REFUND] = true;
            $payment->setDetails($details->getArrayCopy());
        } catch (Exception $e) {
            $this->logger->error("[Alma] Refund error for failed Payment $pid (Alma: $almaPid): " . $e->getMessage());

            $this->addFlash(
                'error',
                'alma_sylius_payment_plugin.payment.failed_not_refunded',
                ['%pid%' => $almaPid]
            );
        }
    }

    public function onPreExecute(Context $context): void
    {
    }

    public function onExecute(Context $context): void
    {
    }
}
