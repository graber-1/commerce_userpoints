<?php

namespace Drupal\commerce_userpoints\Plugin\Commerce;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_currency_resolver\CurrencyHelper;

/**
 * Provides common methods for userpoint deduction functionality.
 */
trait UserpointsDeductionTrait {

  /**
   * Helper function to convert config including currencies.
   *
   * @param array $configuration
   *   The configuration array.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce order entity.
   *
   * @return array
   *   The converted configuration.
   */
  protected function convertConfiguration(array $configuration, OrderInterface $order) {
    $conversion_rate = new Price($configuration['conversion_rate']['number'], $configuration['conversion_rate']['currency_code']);
    $order_currency = $order->getSubTotalPrice()->getCurrencyCode();
    if ($conversion_rate->getCurrencyCode() !== $order_currency) {
      $conversion_rate = CurrencyHelper::priceConversion($conversion_rate, $order_currency);
    }

    $configuration['conversion_rate'] = $conversion_rate;

    return $configuration;
  }

}
