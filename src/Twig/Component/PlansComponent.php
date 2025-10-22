<?php

declare(strict_types=1);

namespace  Alma\SyliusPaymentPlugin\Twig\Component;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;

#[AsLiveComponent(
    name: 'alma:shop:plans',
    route: 'sylius_shop_live_component',
    template: '@AlmaSyliusPaymentPlugin/shop/components/plans.html.twig'
)]
final class PlansComponent
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp(writable: true, updateFromParent: true)]
    public ?int $amount = null;

    #[LiveProp(writable: true, updateFromParent: true)]
    public ?string $locale = null;

    #[LiveProp(writable: true, updateFromParent: true)]
    public array $plans = [];

    #[LiveAction]
    public function dispatch(): void
    {
        if (null === $this->amount || null === $this->locale || empty($this->plans)) {
            return;
        }

        $this->dispatchBrowserEvent('alma:plans:mounted', ['amount' => $this->amount, 'plans' => $this->plans, 'locale' => $this->locale]);
    }

    public function __invoke(): void
    {
        $this->dispatch();
    }
}
