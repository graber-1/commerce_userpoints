<?php

namespace Drupal\commerce_userpoints\Plugin\Commerce\InlineForm;

use Drupal\commerce\Plugin\Commerce\InlineForm\InlineFormBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\userpoints\Service\UserPointsServiceInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an inline form for paying with userpoints.
 *
 * @CommerceInlineForm(
 *   id = "userpoints",
 *   label = @Translation("Userpoints exchange"),
 * )
 */
class UserPointsForm extends InlineFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Userpoints service.
   *
   * @var \Drupal\userpoints\Service\UserPointsServiceInterface
   */
  protected $userpoints;

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
   *   The entity type manager.
   * @param \Drupal\userpoints\Service\UserPointsServiceInterface $userpoints
   *   The Userpoints service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    UserPointsServiceInterface $userpoints
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->userpoints = $userpoints;
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
      $container->get('userpoints.points')
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
    assert($order instanceof OrderInterface);

    if (!empty($order->getData('userpoints_type'))) {
      $inline_form = [
        '#tree' => TRUE,
        '#configuration' => $this->getConfiguration(),
      ] + $inline_form;

      $inline_form['userpoints'] = [
        '#type' => 'number',
        '#min' => 0,
        '#title' => $this->t('Use points', []),
        '#default_value' => 0,
      ];
      $inline_form['apply'] = [
        '#type' => 'submit',
        '#value' => t('Apply userpoints'),
        '#name' => 'apply_userpoints',
        '#limit_validation_errors' => [
          $inline_form['#parents'],
        ],
        '#submit' => [
          [get_called_class(), 'applyUserpoints'],
        ],
        '#ajax' => [
          'callback' => [get_called_class(), 'ajaxRefreshForm'],
          'element' => $inline_form['#parents'],
        ],
      ];

      return $inline_form;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateInlineForm(array &$inline_form, FormStateInterface $form_state) {
    parent::validateInlineForm($inline_form, $form_state);

  }

  /**
   * Submit callback for the "Apply coupon" button.
   */
  public static function applyUserpoints(array $form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $parents = array_slice($triggering_element['#parents'], 0, -1);
    $inline_form = NestedArray::getValue($form, $parents);

    return;
    if (isset($inline_form['userpoints'])) {
      $order_storage = \Drupal::entityTypeManager()->getStorage('commerce_order');
      /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
      $order = $order_storage->load($inline_form['#configuration']['order_id']);
      $order->save();
    }
    $form_state->setRebuild();
  }

}
