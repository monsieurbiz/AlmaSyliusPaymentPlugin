<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use Alma\API\Client as AlmaClient;
use Alma\API\RequestError;
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
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Capture;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RedirectToPaymentPageAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /**
     * @var AlmaBridgeInterface
     */
    protected $api;

    /** @var LoggerInterface */
    protected $logger;

    private ?SessionInterface $session;

    /**
     * @param LoggerInterface $logger
     * @param RequestStack $requestStack
     */
    public function __construct(LoggerInterface $logger, RequestStack $requestStack, private TranslatorInterface $translator)
    {
        $this->apiClass = AlmaBridge::class;
        $this->logger = $logger;
        $this->session = $requestStack->getMainRequest()?->getSession();
    }

    /**
     * @param RedirectToPaymentPage $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var Capture $captureRequest */
        $captureRequest = $request->getModel();

        $model = $captureRequest->getModel();
        $details = ArrayObject::ensureArrayObject($model instanceof PaymentInterface ? $model->getDetails() : $model);
        /** @var PaymentInterface $localPayment */
        $localPayment = $captureRequest->getFirstModel();

        if (!\in_array($localPayment->getState(), [PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING], true)) {
            $this->rejectReopening($localPayment, 'local_state');
        }

        if ($details->offsetExists(AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID)) {
            $this->reopenPayment($localPayment, $details);
        }

        /** @var AlmaClient $alma */
        $alma = $this->api->getDefaultClient();

        try {
            $payment = $alma->payments->create((array) $details[AlmaBridgeInterface::DETAILS_KEY_PAYLOAD]);
        } catch (RequestError $e) {
            $this->logger->error('[Alma] Payment creation failed: ' . $e->getMessage());
            $this->addErrorFlash();

            $this->resetPaymentState($captureRequest->getFirstModel(), $details);

            // In case of error, redirecting to the "after url" will trigger the StatusAction that will assess the
            // status of the payment and redirect to the payment method choices when it's considered "new"
            throw new HttpRedirect((string) $captureRequest->getToken()?->getAfterUrl());
        }

        $details[AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID] = $payment->id;
        $details[AlmaBridgeInterface::DETAILS_KEY_CREATION_CONTEXT] = $this->creationContext($localPayment);
        $captureRequest->getFirstModel()->setDetails($details->getArrayCopy());

        throw new HttpRedirect($payment->url);
    }

    private function creationContext(PaymentInterface $payment): array
    {
        $context = [
            'payment_method_id' => $payment->getMethod()?->getId(),
            'gateway_config_id' => $payment->getMethod()?->getGatewayConfig()?->getId(),
            'merchant_id' => null,
            'api_mode' => null,
            'currency' => $payment->getCurrencyCode(),
        ];
        if (null !== $context['payment_method_id'] && null !== $context['gateway_config_id'] && null !== $context['currency']) {
            $config = $this->api->getGatewayConfig();
            $context['merchant_id'] = $config->getMerchantIdOrNull();
            $context['api_mode'] = $config->getApiModeOrNull();
        }

        return $context;
    }

    private function reopenPayment(PaymentInterface $payment, ArrayObject $details): void
    {
        if (false === $details->get(AlmaBridgeInterface::DETAILS_KEY_IS_VALID)) {
            $this->rejectReopening($payment, 'failed_validation');
        }

        $pid = $details[AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID];
        $context = $details[AlmaBridgeInterface::DETAILS_KEY_CREATION_CONTEXT];
        if (!\is_string($pid) || '' === trim($pid) || !\is_array($context)) {
            $this->rejectReopening($payment, 'missing_identity_or_context');
        }

        $payload = $details[AlmaBridgeInterface::DETAILS_KEY_PAYLOAD];
        $original = \is_array($payload) ? ($payload['payment'] ?? null) : null;
        if (
            !\is_array($original)
            || !\is_int($original['purchase_amount'] ?? null)
            || !\is_int($original['installments_count'] ?? null)
        ) {
            $this->rejectReopening($payment, 'amount_or_installments');
        }

        foreach ($this->creationContext($payment) as $key => $value) {
            if (null === $value || '' === $value || !array_key_exists($key, $context) || $context[$key] !== $value) {
                $this->rejectReopening($payment, 'creation_context');
            }
        }

        try {
            $remote = $this->api->getDefaultClient()->payments->fetch($pid);
        } catch (RequestError $e) {
            $this->rejectReopening($payment, 'fetch_failed', $e);
        }

        if (
            $remote->id !== $pid
            || !\is_array($remote->custom_data)
            || ($remote->custom_data['payment_id'] ?? null) !== $payment->getId()
            || null === $payment->getId()
        ) {
            $this->rejectReopening($payment, 'remote_identity');
        }
        if (
            !\is_int($remote->purchase_amount)
            || $remote->purchase_amount !== ($original['purchase_amount'] ?? null)
            || $remote->purchase_amount !== $payment->getAmount()
            || !\is_int($remote->installments_count)
            || $remote->installments_count !== ($original['installments_count'] ?? null)
            || $remote->installments_count !== $this->api->getGatewayConfig()->getInstallmentsCount()
        ) {
            $this->rejectReopening($payment, 'amount_or_installments');
        }
        if (null !== $remote->expired_at) {
            $this->rejectReopening($payment, 'expired');
        }

        $url = $remote->url;
        $parts = \is_string($url) ? parse_url($url) : false;
        if (
            !\is_string($url) || '' === trim($url)
            || false === filter_var($url, FILTER_VALIDATE_URL)
            || !\is_array($parts)
            || 'https' !== strtolower($parts['scheme'] ?? '')
            || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            $this->rejectReopening($payment, 'redirect_url');
        }

        throw new HttpRedirect($url);
    }

    private function rejectReopening(PaymentInterface $payment, string $cause, ?RequestError $error = null): never
    {
        $this->logger->error('[Alma] Payment reopening rejected', [
            'payment_id' => $payment->getId(),
            'cause' => $cause,
            'exception_class' => null === $error ? null : $error::class,
            'exception_code' => $error?->getCode(),
        ]);
        $key = null === $error ? 'reopening_failed' : 'reopening_unavailable';

        throw new HttpResponse(
            $this->translator->trans('alma_sylius_payment_plugin.payment.' . $key, [], 'flashes'),
            null === $error ? 409 : 503,
            ['content-type' => 'text/plain; charset=UTF-8'],
        );
    }

    private function addErrorFlash(): void
    {
        if (null === $this->session) {
            return;
        }

        /** @var FlashBagInterface $flashBag */
        $flashBag = $this->session->getBag('flashes');
        $flashBag->add('error', 'alma_sylius_payment_plugin.payment.creation_failed');
    }

    private function resetPaymentState(PaymentInterface $payment, ArrayObject $details): void
    {
        // Deleting payment's payload makes the payment "new" again to the eyes of the StatusAction
        $details->offsetUnset(AlmaBridgeInterface::DETAILS_KEY_PAYLOAD);
        $payment->setDetails($details->getArrayCopy());
    }

    public function supports($request): bool
    {
        return
            $request instanceof RedirectToPaymentPage
            && $request->getModel() instanceof Capture;
    }
}
