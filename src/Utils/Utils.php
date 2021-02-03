<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Utils;


use Doctrine\Common\Collections\Collection;
use Sylius\Component\Resource\Model\TranslatableInterface;
use Sylius\Component\Resource\Model\TranslationInterface;

class Utils
{
    /**
     * @param Collection|array $collection
     * @return array
     */
    public static function getCollectionValues($collection): array
    {
        return $collection instanceof Collection ? $collection->getValues() : $collection;
    }

    public static function getTranslationImpl(TranslatableInterface $translatable): TranslationInterface
    {
        $trans = $translatable->getTranslation();
        if ($translatable->getTranslations()->offsetExists('fr_FR')) {
            $trans = $translatable->getTranslation('fr_FR');
        } elseif ($translatable->getTranslations()->offsetExists('en_US')) {
            $trans = $translatable->getTranslation('en_US');
        }

        return $trans;
    }
}
