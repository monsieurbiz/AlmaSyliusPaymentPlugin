<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Request;

use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Payum\Core\Request\Generic;
use Sylius\Component\Core\Model\PaymentInterface;
use Webmozart\Assert\Assert;

class ValidatePayment extends Generic
{
    /** @var PaymentInterface */
    protected $model;

    public function __construct(PaymentInterface $payment, private ?string $candidatePaymentId = null)
    {
        parent::__construct($payment);
        if (null === $candidatePaymentId) {
            Assert::keyExists($payment->getDetails(), AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID);
        }
    }

    public function getCandidatePaymentId(): ?string
    {
        return $this->candidatePaymentId;
    }

    public function getModel(): PaymentInterface
    {
        return $this->model;
    }
}
