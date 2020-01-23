<?php

namespace Drupal\commerce_userpoints\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\userpoints\Service\UserPointsServiceInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Drupal\commerce_order\Event\OrderEvent;

/**
 * Defines the order event subscriber.
 */
class OrderEventSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The order integrator service.
   *
   * @var \Drupal\userpoints\Service\UserPointsServiceInterface
   */
  protected $userpoints;

  /**
   * Constructs a new OrderEventSubscriber object.
   *
   * @param \Drupal\userpoints\Service\UserPointsServiceInterface $userpoints
   *   A userpoints service.
   */
  public function __construct(UserPointsServiceInterface $userpoints) {
    $this->userpoints = $userpoints;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [
      'commerce_order.place.pre_transition' => 'onOrderPlacement',
      'commerce_order.order.paid' => 'onOrderPaid',
    ];
    return $events;
  }

  /**
   * Deduce userpoints where applicable.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The order state change event.
   */
  public function onOrderPlacement(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $usage_data = $order->getData('userpoints_usage', []);
    if (!empty($usage_data)) {
      $user = $order->uid->first()->entity;
      foreach ($usage_data as $userpoints_type => $data) {
        if (!empty($data['count'])) {
          $log = $this->formatPlural(
            $data['count'],
            '1 point exchanged in order @order_id.',
            '@quantity points exchanged in order @order_id.',
            [
              '@quantity' => $data['count'],
              '@order_id' => $order->id(),
            ]
          );

          $this->userpoints->addPoints(-$data['count'], $userpoints_type, $user, $log);
        }
      }
    }
  }

  /**
   * Handle points addition where applicable.
   *
   * @param \Drupal\commerce_order\Event\OrderEvent $event
   *   The order state change event.
   */
  public function onOrderPaid(OrderEvent $event) {
    $order = $event->getOrder();
    foreach ($order->getItems() as $order_item) {
      if ($grant_data = $order_item->getData('userpoints_grants', FALSE)) {
        foreach ($grant_data as $points_type => $count) {
          $log = $this->formatPlural(
            $count,
            '1 point granted on purchase of @item in order @order_id.',
            '@quantity points granted on purchase of @item in order @order_id.',
            [
              '@item' => $order_item->label(),
              '@quantity' => $count,
              '@order_id' => $order->id(),
            ]
          );

          $this->userpoints->addPoints($count, $points_type, $order->uid->first()->entity, $log);
        }
      }
    }
  }

}
