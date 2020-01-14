<?php

namespace Drupal\commerce_userpoints\Plugin\Commerce;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_currency_resolver\CurrencyHelper;

/**
 * Provides common methods for userpoint deduction functionality.
 */
trait UserpointsPromotionTrait {

  /**
   * Helper function to convert config including currencies.
   *
   * @param array $configuration
   *   The configuration array.
   * @param string $currency_code
   *   The Commerce order entity.
   *
   * @return array
   *   The converted configuration.
   */
  protected function convertConfiguration(array $configuration, $currency_code) {
    $conversion_rate = new Price($configuration['conversion_rate']['number'], $configuration['conversion_rate']['currency_code']);
    if ($conversion_rate->getCurrencyCode() !== $currency_code) {
      $conversion_rate = CurrencyHelper::priceConversion($conversion_rate, $currency_code);
    }

    $configuration['conversion_rate'] = $conversion_rate;

    return $configuration;
  }

}
