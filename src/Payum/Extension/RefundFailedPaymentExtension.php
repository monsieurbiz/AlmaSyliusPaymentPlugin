<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Extension;


use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Extension\Context;
use Payum\Core\Extension\ExtensionInterface;
use Payum\Core\Request\GetStatusInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 *  A Payum extension to make sure all payment validation failures lead to a full refund of the payment, so that the
 *  customer isn't left with an ongoing payment but without a validated order.
 */
class RefundFailedPaymentExtension implements ExtensionInterface
{
    /** @var AlmaBridgeInterface */
    protected $api;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var SessionInterface
     */
    private $session;

    public function __construct(
        AlmaBridgeInterface $api,
        LoggerInterface $logger,
        SessionInterface $session
    ) {
        $this->api = $api;
        $this->logger = $logger;
        $this->session = $session;
    }

    private function addFlash(string $type, string $message, ?array $params = []): void
    {
        /** @var FlashBagInterface $flashBag */
        $flashBag = $this->session->getBag('flashes');

        // Avoid duplicate flashes
        /** @var array|string $flash */
        foreach ($flashBag->peek($type) as $flash) {
            if (is_array($flash) && array_key_exists('message', $flash) && $flash['message'] === $message) {
                return;
            }
        }

        $flashBag->add($type, ['message' => $message, 'parameters' => $params]);
    }

    /**
     * @inheritDoc
     */
    public function onPostExecute(Context $context): void
    {
        $request = $context->getRequest();

        if ($request instanceof GetStatusInterface === false) {
            return;
        }

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();
        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        // Only check new/processing payments that have a `false` is_valid value (exact value; null/non-existing value
        // should return) in their details data and hasn't been already refunded because of a validation error
        if (
            !in_array($payment->getState(), [PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING], true) ||
            $details->get(AlmaBridgeInterface::DETAILS_KEY_IS_VALID) !== false ||
            $details->get(AlmaBridgeInterface::DETAILS_KEY_ERROR_TRIGGERED_REFUND) === true
        ) {
            return;
        }

        /** @var int $pid */
        $pid = $payment->getId();

        /** @var string $almaPid */
        $almaPid = $details[AlmaBridge::DETAILS_KEY_PAYMENT_ID];

        $alma = $this->api->getDefaultClient();

        try {
            $alma->payments->refund($almaPid);
            $this->addFlash('info', 'alma_sylius_payment_plugin.payment.failed_refunded');

            $details[AlmaBridgeInterface::DETAILS_KEY_ERROR_TRIGGERED_REFUND] = true;
            $payment->setDetails($details->getArrayCopy());
        } catch (\Exception $e) {
            $this->logger->error("[Alma] Refund error for failed Payment $pid (Alma: $almaPid): " . $e->getMessage());

            $this->addFlash(
                'error',
                'alma_sylius_payment_plugin.payment.failed_not_refunded',
                ['%pid%' => $almaPid]
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function onPreExecute(Context $context): void
    {

    }

    /**
     * @inheritDoc
     */
    public function onExecute(Context $context): void
    {

    }
}
