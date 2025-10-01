<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AlmaExtension extends AbstractExtension
{
    /**
     * @return TwigFilter[]
     */
    public function getFilters()
    {
        return [
            new TwigFilter('alma_price_from_cent', [$this, 'formatPriceFromCent']),
            new TwigFilter('alma_format_percent_number', [$this, 'formatPercentNumber']),
        ];
    }

    /**
     * @param int $number
     *
     * @return float|int
     */
    public function formatPriceFromCent(int $number)
    {
        return $number / 100;
    }

    /**
     * @param int $number
     *
     * @return string
     */
    public function formatPercentNumber(int $number): string
    {
        return \sprintf('%s %%', number_format($number / 100, 2, ',', ' '));
    }
}
