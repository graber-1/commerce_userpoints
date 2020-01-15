<?php

namespace Drupal\commerce_userpoints\Plugin\Commerce\InlineForm;

use Drupal\commerce\Plugin\Commerce\InlineForm\InlineFormBase;
use Drupal\commerce_userpoints\Plugin\Commerce\UserpointsPromotionTrait;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\userpoints\Service\UserPointsServiceInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_order\PriceSplitterInterface;
use Drupal\commerce_order\Adjustment;

/**
 * Provides an inline form for paying with userpoints.
 *
 * @CommerceInlineForm(
 *   id = "userpoints",
 *   label = @Translation("Userpoints exchange"),
 * )
 */
class UserPointsForm extends InlineFormBase {

  use UserpointsPromotionTrait;

  /**
   * An entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A Userpoints service.
   *
   * @var \Drupal\userpoints\Service\UserPointsServiceInterface
   */
  protected $userpoints;

  /**
   * A price rounder service.
   *
   * @var \Drupal\commerce_price\RounderInterface
   */
  protected $rounder;

  /**
   * A currency formatter service.
   *
   * @var \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface
   */
  protected $currencyFormatter;

  /**
   * A price splitter service.
   *
   * @var \Drupal\commerce_order\PriceSplitterInterface
   */
  protected $splitter;

  /**
   * Constructs a new CouponRedemption object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   An entity type manager.
   * @param \Drupal\userpoints\Service\UserPointsServiceInterface $userpoints
   *   A Userpoints service.
   * @param \Drupal\commerce_price\RounderInterface $rounder
   *   A price rounder service.
   * @param \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface $currency_formatter
   *   A currency formatter service.
   * @param \Drupal\commerce_order\PriceSplitterInterface $splitter
   *   A price splitter service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    UserPointsServiceInterface $userpoints,
    RounderInterface $rounder,
    CurrencyFormatterInterface $currency_formatter,
    PriceSplitterInterface $splitter
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->userpoints = $userpoints;
    $this->rounder = $rounder;
    $this->currencyFormatter = $currency_formatter;
    $this->splitter = $splitter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('userpoints.points'),
      $container->get('commerce_price.rounder'),
      $container->get('commerce_price.currency_formatter'),
      $container->get('commerce_order.price_splitter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      // The order_id is passed via configuration to avoid serializing the
      // order, which is loaded from scratch in the submit handler to minimize
      // chances of a conflicting save.
      'order_id' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function requiredConfiguration() {
    return ['order_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildInlineForm(array $inline_form, FormStateInterface $form_state) {
    $inline_form = parent::buildInlineForm($inline_form, $form_state);

    $order = $this->entityTypeManager->getStorage('commerce_order')->load($this->configuration['order_id']);
    $userpoints_config = $order->getData('userpoints_config');

    if (!empty($userpoints_config) && !empty($userpoints_config['points_type'])) {
      $config = $this->convertConfiguration($userpoints_config, $order->getSubTotalPrice()->getCurrencyCode());

      // Check if the user has any points to exchange for currency.
      $points_count = $this->userpoints->getPoints($order->uid->first()->entity, $config['points_type']);

      $conversion_rate = 1 / ($config['conversion_amount'] * $config['conversion_rate']->getNumber());
      $max_currency = new Price($points_count * $conversion_rate, $config['conversion_rate']->getCurrencyCode());
      $max_currency = $this->rounder->round($max_currency);

      // Update available points count not to exceed the order total.
      $subtotal_price = $order->getSubTotalPrice();
      if ($max_currency->greaterThan($subtotal_price)) {
        $points_count = ceil($subtotal_price->getNumber() / $conversion_rate);
      }

      if ($max_currency->getNumber() >= 0.01) {
        $inline_form = [
          '#tree' => TRUE,
          '#configuration' => $this->getConfiguration() + $userpoints_config,
        ] + $inline_form;

        $points_type = $this->entityTypeManager->getStorage('userpoints_type')->load($config['points_type']);
        $usage_data = $order->getData('userpoints_usage', []);

        $inline_form['userpoints'] = [
          '#type' => 'number',
          '#min' => 0,
          '#max' => $points_count,
          '#step' => 1,
          '#title' => $this->t('Use your points'),
          '#description' => $this->formatPlural(
            $config['conversion_amount'],
            'Use the following amount of "@type" points to deduce from order total (@points point = @amount, you can use @max points).',
            'Use the following amount of "@type" points to deduce from order total (@points points = @amount, you can use @max points).',
            [
              '@type' => $points_type->label(),
              '@points' => $config['conversion_amount'],
              '@amount' => $this->currencyFormatter->format($config['conversion_rate']->getNumber(), $config['conversion_rate']->getCurrencyCode()),
              '@max' => $points_count,
            ]
          ),
          '#default_value' => isset($usage_data[$config['points_type']]) ? $usage_data[$config['points_type']]['count'] : 0,
        ];
        $inline_form['apply'] = [
          '#type' => 'submit',
          '#value' => t('Apply userpoints'),
          '#name' => 'apply_userpoints',
          '#limit_validation_errors' => [
            $inline_form['#parents'],
          ],
          '#submit' => [
            [$this, 'applyUserpoints'],
          ],
          '#ajax' => [
            'callback' => [get_called_class(), 'ajaxRefreshForm'],
            'element' => $inline_form['#parents'],
          ],
        ];
      }

      return $inline_form;
    }
  }

  /**
   * Submit callback for the "Apply userpoints" button.
   */
  public function applyUserpoints(array $form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $parents = array_slice($triggering_element['#parents'], 0, -1);
    $inline_form = NestedArray::getValue($form, $parents);
    $parents[] = 'userpoints';
    $points_count = $form_state->getValue($parents);

    $order = $this->entityTypeManager->getStorage('commerce_order')->load($inline_form['#configuration']['order_id']);

    // Add / update points usage data.
    $usage_data = $order->getData('userpoints_usage', []);
    if ($points_count) {
      $usage_data[$inline_form['#configuration']['points_type']] = $inline_form['#configuration'] + [
        'count' => $points_count
      ];
    }
    else {
      unset($usage_data[$inline_form['#configuration']['points_type']]);
    }

    $order->setData('userpoints_usage', $usage_data);

    $order->save();
    $form_state->setRebuild();
  }

}
