<?php

namespace Drupal\commerce_userpoints\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\userpoints\Service\UserPointsServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\rest\ModifiedResourceResponse;

/**
 * Provides a resource to exchange userpoints.
 *
 * @RestResource(
 *   id = "commerce_userpoints_rest_resource",
 *   label = @Translation("Commerce Userpoints rest resource"),
 *   uri_paths = {
 *     "https://www.drupal.org/link-relations/create" = "/api/commerce-userpoints",
 *   }
 * )
 */
class CommerceUserpointsResource extends ResourceBase {
  /**
   * The current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Entity Type Manager instance.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * A cart provider service.
   *
   * @var \Drupal\commerce_cart\CartProviderInterface
   */
  protected $cartProvider;

  /**
   * A Userpoints service.
   *
   * @var \Drupal\userpoints\Service\UserPointsServiceInterface
   */
  protected $userpoints;

  /**
   * Constructs a new BookingRestResource object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A current user instance.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Symfony\Component\HttpFoundation\Request $currentRequest
   *   The current request.
   * @param \Drupal\commerce_cart\CartProviderInterface $cartProvider
   *   A cart prvider service.
   * @param \Drupal\userpoints\Service\UserPointsServiceInterface $userpoints
   *   A Userpoints service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user,
    EntityTypeManagerInterface $entityTypeManager,
    Request $currentRequest,
    CartProviderInterface $cartProvider,
    UserPointsServiceInterface $userpoints
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);

    $this->currentUser = $current_user;
    $this->entityTypeManager = $entityTypeManager;
    $this->currentRequest = $currentRequest;
    $this->cartProvider = $cartProvider;
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
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('iko_messages_api'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('commerce_cart.cart_provider'),
      $container->get('userpoints.points')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function permissions() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function getBaseRouteRequirements($method) {
    return [
      '_access' => 'TRUE',
    ];
  }

  /**
   * Bad request response handler.
   */
  protected function invalidRequest($message) {
    return new JsonResponse([
      'status' => 'error',
      'message' => $message,
    ], 400);
  }

  /**
   * Common access callback for every operation.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  protected function checkRequest(&$data) {
    $access = FALSE;
    if (empty($data['order_id'])) {
      if (empty($data['store_id'])) {
        $data['store_id'] = 'default';
        $data['order'] = $this->cartProvider->getCart($data['store_id']);
        if (!$data['order']) {
          return $this->invalidRequest(sprintf('No cart found for the current user in the "%s" store.', $data['store_id']));
        }
      }
    }
    else {
      $data['order'] = $this->entityTypeManager->getStorage('commerce_order')->load($data['order_id']);
      if (!$data['order']) {
        return $this->invalidRequest('Invalid "order_id" parameter.');
      }
    }

    if (empty($data['points_type'])) {
      return $this->invalidRequest('"points_type" parameter not provided.');
    }
    elseif (!$this->entityTypeManager->getStorage('userpoints_type')->load($data['points_type'])) {
      return $this->invalidRequest('Invalid "points_type" parameter.');
    }

    if (!isset($data['amount']) || !preg_match('/^[0-9]*$/', $data['amount'])) {
      return $this->invalidRequest('"amount" parameter not provided or invalid.');
    }

    if ($this->currentUser->hasPermission('administer commerce_promotion') || $this->currentUser->hasPermission('update any commerce_promotion')) {
      $access = TRUE;
    }
    elseif (!empty($data['order']->uid) && $data['order']->uid->first()->target_id == $this->currentUser->id()) {
      $access = TRUE;
    }
    if (!$access) {
      throw new AccessDeniedHttpException("Access denied.");
    }

    // Chcek if the order has the userpoints deduction promotion enabled.
    $userpoints_config = $data['order']->getData('userpoints_config');
    if (empty($userpoints_config) || $userpoints_config['points_type'] != $data['points_type']) {
      return $this->invalidRequest(sprintf("Userpoints deduction promotion is not allowed for %s type userpoints on this cart (check promotion settings).", $data['points_type']));
    }

    // Check if the user has enough userpoints of the selected type.
    if ($this->userpoints->getPoints($data['order']->uid->first()->entity, $data['points_type']) < $data['amount']) {
      return $this->invalidRequest(sprintf("User %d doesn't have %d %s type userpoints.", $data['order']->uid->first()->target_id, $data['amount'], $data['points_type']));
    }

    return TRUE;
  }

  /**
   * POST method callback.
   */
  public function post(array $data) {
    $result = $this->checkRequest($data);
    if ($result !== TRUE) {
      return $result;
    }

    $usage_data = $data['order']->getData('userpoints_usage', []);
    if ($data['amount']) {
      $usage_data[$data['points_type']]['count'] = $data['amount'];
      $message = sprintf('%d %s type userpoints set to be deduced from order total.', $data['amount'], $data['points_type']);
    }
    else {
      unset($usage_data[$data['points_type']]);
      $message = 'Cancelled userpoints deduction from order total.';
    }

    $data['order']->setData('userpoints_usage', $usage_data);
    $data['order']->save();

    return new ModifiedResourceResponse($message, 200);
  }

}
