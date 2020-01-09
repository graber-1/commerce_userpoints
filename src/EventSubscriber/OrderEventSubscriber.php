<?php

namespace Drupal\commerce_userpoints\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\userpoints\Service\UserPointsServiceInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;

/**
 * Defines the order event subscriber.
 */
class OrderEventSubscriber implements EventSubscriberInterface {

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
    ];
    return $events;
  }

  /**
   * Finalizes the cart when the order is placed.
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

}
