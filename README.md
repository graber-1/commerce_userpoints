Commerce Userpoints
===================

Provides integration of Userpoints module (https://github.com/graber-1/userpoints) with Drupal Commerce 2.0 promotions API, including the following:
 * promotion type that allows to reduce order amount by using userpoints of
   a selected type,
 * promotion type that allows to add userpoints of a selected type basing on
   order line items amounts (fixed and amount-based),
 * A REST endpoint that allows to set userpoints deduction amount on a cart.


Setup instructions
==================

 * Install as any other module.
 * Userpoints grant promotions work as any other order item type promotions.
 * Userpoints deduction promotion needs the "Userpoints exchange" inline form to
   be included in the checkout flow (admin/commerce/config/checkout-flows).


REST endpoint
=============

Allows to set userpoints deduction value on a cart.

Method: POST
URI: api/commerce-userpoints
Parameters:
 * order_id (if not provided, the current user's cart from the given
   store will be used): the affected cart ID,
 * store_id (default: default): used to get the current user's cart
   if order_id is not provided.
 * points_type: ID of the userpoints type to use,
 * amount: amount of points to exchange, zero cancels a previously set exchange.

The simplest request specifies only the points_type and amount. In such a case the amount will be deduced from the total amount of the current user's cart in the default store.
