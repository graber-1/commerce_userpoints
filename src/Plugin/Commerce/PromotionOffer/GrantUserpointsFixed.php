<?php

namespace Drupal\commerce_userpoints\Plugin\Commerce\PromotionOffer;

use Drupal\commerce_promotion\Plugin\Commerce\PromotionOffer\OrderItemPromotionOfferBase;
use Drupal\commerce_price\RounderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\commerce_promotion\Entity\CouponInterface;

use Drupal\commerce_order\Adjustment;

/**
 * Provides a promotion that gives users points for purchases.
 *
 * @CommercePromotionOffer(
 *   id = "user_points_grant_fixed",
 *   label = @Translation("Add a fixed user points amount per product."),
 *   entity_type = "commerce_order",
 * )
 */
class GrantUserpointsFixed extends OrderItemPromotionOfferBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new OrderPromotionOfferBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The pluginId for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_price\RounderInterface $rounder
   *   The rounder.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RounderInterface $rounder, EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $rounder, $splitter);

    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('commerce_price.rounder'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'points_type' => '',
      'amount' => 1,
    ] + parent::defaultConfiguration();
  }

  /**
   * Get a single configuration value.
   *
   * @param string $key
   *   The configuration item name.
   */
  public function getConfigurationItem($key) {
    $this->configuration += $this->defaultConfiguration();
    if (!isset($this->configuration[$key])) {
      throw new PluginException('Invalid configuration key specified.');
    }
    return $this->configuration[$key];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $point_types = $this->entityTypeManager->getStorage('userpoints_type')->loadMultiple();
    $options = [];
    foreach ($point_types as $id => $type) {
      $options[$id] = $type->label();
    }

    $form['points_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Points type'),
      '#options' => $options,
      '#default_value' => $this->getConfigurationItem('points_type'),
      '#required' => TRUE,
      '#weight' => -1,
    ];

    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Conversion amount'),
      '#description' => $this->t('This amount of points will be added on purchase of a matching product.'),
      '#default_value' => $this->getConfigurationItem('conversion_amount'),
      '#required' => TRUE,
      '#weight' => -1,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      foreach ($this->defaultConfiguration() as $key => $default) {
        if (isset($values[$key])) {
          $this->configuration[$key] = $values[$key];
        }
        else {
          $this->configuration[$key] = $default;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function apply(EntityInterface $entity, PromotionInterface $promotion, CouponInterface $coupon = NULL) {
    $this->assertEntity($entity);
    /* @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order_item = $entity;

    $config = $this->getConfigurationItem();

    $grant_data = $order_item->getData('userpoints_grants', []);
    $grant_data[$this->getConfigurationItem('points_type')] = $this->getConfigurationItem('amount') * $order_item->getQuantity();
    $order_item->setData('userpoints_grants', $grant_data);
  }

}
