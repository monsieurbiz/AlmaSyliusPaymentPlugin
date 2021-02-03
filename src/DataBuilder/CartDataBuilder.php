<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\DataBuilder;


use Alma\SyliusPaymentPlugin\Utils\Utils;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Sylius\Component\Core\Model\ImageInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Taxation\Resolver\TaxRateResolverInterface;
use Sylius\Component\Taxonomy\Model\TaxonTranslationInterface;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;
use Webmozart\Assert\Assert;

class CartDataBuilder implements DataBuilderInterface
{
    /**
     * @var RouterInterface
     */
    private $router;
    /**
     * @var CacheManager
     */
    private $liipCacheManager;
    /**
     * @var TaxRateResolverInterface
     */
    private $taxRateResolver;

    public function __construct(
        RouterInterface $router,
        CacheManager $liipCacheManager,
        TaxRateResolverInterface $taxRateResolver
    ) {
        $this->router = $router;
        $this->liipCacheManager = $liipCacheManager;
        $this->taxRateResolver = $taxRateResolver;
    }

    public function __invoke(array $data, PaymentInterface $payment): array
    {
        $order = $payment->getOrder();
        Assert::notNull($order);

        $data['payment']['cart'] = [
            'items' => $this->getItems($order),
            'discounts' => $this->getDiscounts($order)
        ];

        return $data;
    }

    private function getItems(OrderInterface $order): array
    {
        $items = Utils::getCollectionValues($order->getItems());

        return array_filter(array_map(function (OrderItemInterface $item) {
            $product = $item->getProduct();
            if (!$product) {
                return null;
            }

            /** @var ProductTranslationInterface $productTrans */
            $productTrans = Utils::getTranslationImpl($product);

            // If this product has no variant, use the product directly
            /** @var ProductVariantInterface $variant */
            $variant = $item->getVariant();

            /** @var ProductTranslationInterface $variantTrans */
            $variantTrans = Utils::getTranslationImpl($variant);

            return [
                'sku' => $variant->getCode(),
                'vendor' => null,
                'title' => $productTrans->getName(),
                'variant_title' => $variantTrans->getName(),
                'quantity' => $item->getQuantity(),
                'unit_price' => $item->getDiscountedUnitPrice(),
                'line_price' => $item->getTotal(),
                'is_gift' => $item->getTotal() === 0,
                'categories' => $this->getCategories($product),
                'url' => $this->getProductUrl($product),
                'picture_url' => $this->getProductPictureUrl($variant),
                'requires_shipping' => $variant->isShippingRequired(),
                'taxes_included' => $this->isTaxIncluded($variant),
            ];
        }, $items));
    }

    /**
     * Return a list of all "taxons" a product is associated to, in the shape ['Root > Parent > ... > Taxon', ...]
     *
     * @param ProductInterface $product
     * @return string[]
     */
    private function getCategories(ProductInterface $product): array
    {
        $taxons = Utils::getCollectionValues($product->getTaxons());

        return array_map(function (TaxonInterface $taxon) {
            // Keep all taxons but the root one, then sort by taxon level
            $ancestors = array_filter(Utils::getCollectionValues($taxon->getAncestors()), function (TaxonInterface $t) {
                return $t->getParent() !== null;
            });

            usort($ancestors, function (TaxonInterface $a, TaxonInterface $b): int {
                if ($a->getLevel() === $b->getLevel()) {
                    return 0;
                }

                return ($a->getLevel() < $b->getLevel()) ? -1 : 1;
            });


            return implode(' > ', array_map(function (TaxonInterface $taxon) {
                /** @var TaxonTranslationInterface $trans */
                $trans = Utils::getTranslationImpl($taxon);

                return $trans->getName();
            }, array_merge($ancestors, [$taxon])));
        }, $taxons);
    }

    private function getProductUrl(ProductInterface $product): string
    {
        /** @var ProductTranslationInterface $trans */
        $trans = Utils::getTranslationImpl($product);

        return $this->router->generate(
            'sylius_shop_product_show',
            ['slug' => $trans->getSlug()],
            Router::ABSOLUTE_URL
        );
    }

    private function getProductPictureUrl(ProductVariantInterface $variant): string
    {
        /** @var ProductVariantInterface|ProductInterface $subject */
        $subject = $variant->hasImages() ? $variant : $variant->getProduct();

        /** @var string|null $path */
        $path = null;

        /** @var ImageInterface[] $mainPictures */
        $mainPictures = $subject->getImagesByType('main');

        /** @var ImageInterface[] $images */
        $images = $subject->getImages();

        if (count($mainPictures) > 0) {
            $path = $mainPictures[0]->getPath();
        } elseif (count($images) > 0) {
            $path = $images[0]->getPath();
        }

        $url = "https://placehold.it/200x200";
        if ($path !== null) {
            $url = $this->liipCacheManager->getBrowserPath($path, 'sylius_shop_product_large_thumbnail');
        }

        return $url;
    }

    private function isTaxIncluded(ProductVariantInterface $variant): bool
    {
        $taxRate = $this->taxRateResolver->resolve($variant);
        if ($taxRate === null) {
            return false;
        }

        return $taxRate->isIncludedInPrice();
    }

    private function getDiscounts(OrderInterface $order): array
    {
        /** @var PromotionInterface[] $promotions */
        $promotions = Utils::getCollectionValues($order->getPromotions());

        if (count($promotions) === 0) {
            return [];
        } elseif (count($promotions) === 1) {
            return [
                [
                    'title' => $promotions[0]->getName(),
                    'amount' => $order->getOrderPromotionTotal()
                ]
            ];
        }

        $allPromos = array_map(function (PromotionInterface $promotion) {
            return [
                'title' => $promotion->getName() . " (montant inconnu)",
                'amount' => 0
            ];
        }, $promotions);

        $allPromos[] = [
            'title' => 'Total',
            'amount' => $order->getOrderPromotionTotal()
        ];

        return $allPromos;
    }
}
