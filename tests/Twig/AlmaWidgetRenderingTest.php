<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\Twig;

use Alma\API\Entities\FeePlan;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\DataBuilder\EligibilityDataBuilder;
use Alma\SyliusPaymentPlugin\Helper\EligibilityHelper;
use Alma\SyliusPaymentPlugin\Helper\FeePlansHelper;
use Alma\SyliusPaymentPlugin\Twig\AlmaExtension;
use Alma\SyliusPaymentPlugin\Twig\Component\PlansComponent;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\UX\LiveComponent\LiveResponder;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AlmaWidgetRenderingTest extends TestCase
{
    private const MERCHANT = "merchant_'\"\\\n</script><script>throw 'unsafe';</script>&";
    private const PLANS = [
        ['installmentsCount' => 3, 'deferredDays' => 0, 'deferredMonths' => 0, 'minAmount' => 10001, 'maxAmount' => 300001],
        ['installmentsCount' => 24, 'deferredDays' => 0, 'deferredMonths' => 0, 'minAmount' => 20001, 'maxAmount' => 600001],
    ];

    public function testMissingMerchantRendersNeitherAssetsNorProductOrCartWidget(): void
    {
        $props = [];
        $twig = $this->environment('', $props);
        foreach (['scripts', 'styles', 'component'] as $template) {
            foreach ([
                ['variant' => ['price' => 12345]],
                ['resource' => ['total' => 54321]],
            ] as $context) {
                self::assertSame('', trim($twig->render($this->template($template), $this->context($context))));
            }
        }
        self::assertSame([], $props);
    }

    public function testAssetsArePairedV4AndMerchantCannotBreakOutOfInlineScript(): void
    {
        $props = [];
        $twig = $this->environment(self::MERCHANT, $props);
        $document = $this->document(
            $twig->render($this->template('styles')) . $twig->render($this->template('scripts'))
        );
        $xpath = new \DOMXPath($document);
        $scripts = $xpath->query('//script[@src]');
        $styles = $xpath->query('//link[@rel="stylesheet"]');

        self::assertCount(1, $scripts);
        self::assertCount(1, $styles);
        self::assertSame('https://cdn.jsdelivr.net/npm/@alma/widgets@4.x/dist/widgets.umd.js', $scripts->item(0)->getAttribute('src'));
        self::assertSame('https://cdn.jsdelivr.net/npm/@alma/widgets@4.x/dist/widgets.min.css', $styles->item(0)->getAttribute('href'));
        self::assertCount(1, $xpath->query('//script[not(@src)]'), 'Merchant data must not create script elements');
        self::assertStringNotContainsString('</script>', $xpath->query('//script[not(@src)]')->item(0)->textContent);
    }

    public function testRenderedProductAndCartEventsMountAndUpdateTheV4WidgetOffline(): void
    {
        $props = [];
        $twig = $this->environment(self::MERCHANT, $props);
        $scenarios = [];
        foreach ([
            ['product initial', ['variant' => ['price' => 12345]], 'fr_FR', 12345, 'fr-FR'],
            ['quantity update', ['variant' => ['price' => 12345], 'formValues' => ['cartItem' => ['quantity' => 3]]], 'fr_FR', 37035, 'fr-FR'],
            ['variant update', ['variant' => ['price' => 20001], 'formValues' => ['cartItem' => ['quantity' => 3]]], 'en_US', 60003, 'en-US'],
            ['cart initial', ['resource' => ['total' => 71237]], 'es_ES', 71237, 'es-ES'],
            ['cart update', ['resource' => ['total' => 90501]], 'it_IT', 90501, 'it-IT'],
            ['German locale', ['resource' => ['total' => 90501]], 'de_DE', 90501, 'de-DE'],
            ['Dutch locale', ['resource' => ['total' => 90501]], 'nl_NL', 90501, 'nl-NL'],
            ['Belgian Dutch locale', ['resource' => ['total' => 90501]], 'nl_BE', 90501, 'nl-BE'],
            ['Portuguese locale', ['resource' => ['total' => 90501]], 'pt_PT', 90501, 'pt-PT'],
            ['normalized regional locale', ['resource' => ['total' => 90501]], 'nl-BE', 90501, 'nl-BE'],
            ['short locale', ['resource' => ['total' => 90501]], 'fr', 90501, 'fr'],
        ] as [$name, $context, $locale, $amount, $widgetLocale]) {
            $html = $twig->render($this->template('component'), $this->context($context, $locale));
            $xpath = new \DOMXPath($this->document($html));
            self::assertCount(1, $xpath->query('//*[@id="alma-widget"]'), $name);
            self::assertCount(1, $xpath->query('//*[@data-action="live:connect->live#action"][@data-live-action-param="dispatch"]'), $name);
            $data = $props[array_key_last($props)];
            self::assertSame(['amount' => $amount, 'plans' => self::PLANS, 'locale' => $locale], $data, $name);
            $scenarios[] = [
                'name' => $name,
                'event' => $this->event($data),
                'amount' => $amount,
                'locale' => $widgetLocale,
                'plans' => self::PLANS,
            ];
        }

        $updatedPlans = [
            ['installmentsCount' => 3, 'deferredDays' => 30, 'deferredMonths' => 0, 'minAmount' => 15001, 'maxAmount' => 400001],
            ['installmentsCount' => 4, 'deferredDays' => 0, 'deferredMonths' => 2, 'minAmount' => 25001, 'maxAmount' => 500001],
        ];
        $scenarios[] = [
            'name' => 'plans update',
            'event' => $this->event(['amount' => 90501, 'plans' => $updatedPlans, 'locale' => 'nl_BE']),
            'amount' => 90501,
            'locale' => 'nl-BE',
            'plans' => $updatedPlans,
        ];
        $script = (new \DOMXPath($this->document($twig->render($this->template('scripts')))))
            ->query('//script[not(@src)]')->item(0)->textContent;
        $this->runJavaScript(['merchantId' => self::MERCHANT, 'script' => $script, 'scenarios' => $scenarios]);
    }

    private function environment(string $merchant, array &$props): Environment
    {
        $gateway = $this->createMock(GatewayConfigInterface::class);
        $gateway->method('getFactoryName')->willReturn('alma_payments');
        $gateway->method('getConfig')->willReturn(['merchant_id' => $merchant]);
        $method = new PaymentMethod();
        $method->setGatewayConfig($gateway);
        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $repository->method('findEnabledForChannel')->willReturn([$method]);
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn(new Channel());
        $bridge = $this->createMock(AlmaBridgeInterface::class);
        $bridge->expects('' === $merchant ? self::never() : self::any())->method('initialize');
        $bridge->method('getFeePlans')->willReturn(array_map(
            static fn (array $plan): FeePlan => new FeePlan([
                'installments_count' => $plan['installmentsCount'],
                'deferred_days' => $plan['deferredDays'],
                'deferred_months' => $plan['deferredMonths'],
                'min_purchase_amount' => $plan['minAmount'],
                'max_purchase_amount' => $plan['maxAmount'],
            ]),
            self::PLANS
        ));
        $extension = new AlmaExtension(
            new EligibilityHelper($bridge, new EligibilityDataBuilder()),
            new FeePlansHelper($bridge, $repository, $channelContext, new ArrayAdapter()),
            $this->createMock(LocaleContextInterface::class)
        );
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 2) . '/templates', 'AlmaSyliusPaymentPlugin');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension($extension);
        $twig->addFilter(new TwigFilter('sylius_calculate_price', static function (array $variant, array $options): int {
            self::assertSame(['channel' => 'test-channel'], $options);

            return $variant['price'];
        }));
        // Capture only the Symfony component() boundary; render the actual child template.
        $twig->addFunction(new TwigFunction('component', function (string $name, array $data) use ($twig, &$props): string {
            self::assertSame('alma:shop:plans', $name);
            $props[] = $data;

            return $twig->render('@AlmaSyliusPaymentPlugin/shop/components/plans.html.twig', ['attributes' => '']);
        }, ['is_safe' => ['html']]));

        return $twig;
    }

    private function event(array $data): array
    {
        $component = new PlansComponent();
        $responder = new LiveResponder();
        $component->setLiveResponder($responder);
        $component->amount = $data['amount'];
        $component->plans = $data['plans'];
        $component->locale = $data['locale'];
        $component();
        $events = $responder->getBrowserEventsToDispatch();
        self::assertSame([['event' => 'alma:plans:mounted', 'payload' => $data]], $events);

        return $events[0];
    }

    private function context(array $context, string $locale = 'fr_FR'): array
    {
        return ['hookable_metadata' => ['context' => $context], 'sylius' => ['localeCode' => $locale, 'channel' => 'test-channel']];
    }

    private function template(string $name): string
    {
        return '@AlmaSyliusPaymentPlugin/shop/widget/' . $name . '.html.twig';
    }

    private function document(string $html): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);

        return $document;
    }

    private function runJavaScript(array $fixture): void
    {
        $process = proc_open(['node', dirname(__DIR__) . '/Widget/run-rendered-widget.cjs'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process, 'Node must be installed for the offline widget regression test');
        fwrite($pipes[0], json_encode($fixture, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $error);
        self::assertSame(sprintf("OK: %d rendered events, %d offline remounts\n", count($fixture['scenarios']), count($fixture['scenarios']) - 1), $output);
    }
}
