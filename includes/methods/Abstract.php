<?php

abstract class methods_Abstract
{

  abstract public function submit_form($payment_method, $pane_values, $checkout_pane, $order);

  public function submitFormCharge($payment_method, $pane_form, $pane_values, $order, $charge) {

    $config = array();
    $shipping_array = array();
    $order_wrapper = entity_metadata_wrapper('commerce_order', $order);

    $billing_address = $order_wrapper->commerce_customer_billing->commerce_customer_address->value();
    $order_array = $order_wrapper->commerce_order_total->value();
    $default_currency = commerce_default_currency();
    $amountCents = number_format(commerce_currency_convert($charge['amount'], $order_array['currency_code'], $default_currency), 0, '', '');
    $config['authorization'] = $payment_method['settings']['private_key'];
    $config['mode'] = $payment_method['settings']['mode'];
    $currency_code = $order_array['currency_code'];
    $i = 0;

    $config['postedParam'] = array(
        'email' => $order->mail,
        'value' => $amountCents,
        'currency' => $default_currency,
        'trackId' => $order->order_id,
        'card' => array(
            'name' => "{$billing_address['first_name']} {$billing_address['last_name']}",
            'billingDetails' => array(
                'addressLine1' => $billing_address['thoroughfare'],
                'addressLine2' => $billing_address['premise'],
                'postcode' => $billing_address['postal_code'],
                'country' => $billing_address['country'],
                'city' => $billing_address['locality'],
            )
        )
    );
    $products = null;
    
    foreach ($order_wrapper->commerce_line_items as $delta => $line_item_wrapper) {
      $product_id = $line_item_wrapper->commerce_product->raw();
      $product = commerce_product_load($product_id);

      $price = commerce_product_calculate_sell_price($product);
      $sell_price = number_format(commerce_currency_amount_to_decimal($price['amount'], $price['currency_code']), 2, '.', '');

      // Add the line item to the return array.
      $products[$i] = array(
          'productName' => commerce_line_item_title($line_item_wrapper->value()),
          'price' => $sell_price,
          'quantity' => round($line_item_wrapper->quantity->value()),
          'sku' => ''
      );

      // If it was a product line item, add the SKU.
      if (in_array($line_item_wrapper->type->value(), commerce_product_line_item_types())) {
        $products[$i]['sku'] = $line_item_wrapper->line_item_label->value();
      }

      $i++;
    }
    if ($products && !empty($products)) {
      $config['postedParam']['products'] = $products;
    }
    if (module_exists('commerce_shipping') && !empty($order_wrapper->commerce_customer_shipping->commerce_customer_address)) {
      $shipping_address = $order_wrapper->commerce_customer_shipping->commerce_customer_address->value();

      // Add the shipping address parameters to the request.
      $shipping_array = array(
          'addressLine1' => $shipping_address['thoroughfare'],
          'addressLine2' => $shipping_address['premise'],
          'postcode' => $shipping_address['postal_code'],
          'country' => $shipping_address['country'],
          'city' => $shipping_address['locality'],
      );

      $config['postedParam']['shippingDetails'] = $shipping_array;
    }


    if ($payment_method['settings']['payment_action'] == COMMERCE_CREDIT_AUTH_CAPTURE) {
      $config = array_merge_recursive($this->_captureConfig($payment_method), $config);
    }
    else {
      $config = array_merge_recursive($this->_authorizeConfig($payment_method), $config);
    }

    return $config;
  }

  protected function _placeorder($config, $charge, $order, $payment_method) {

    //building charge
    $respondCharge = $this->_createCharge($config);

    $transaction = commerce_payment_transaction_new('commerce_checkoutpayment', $order->order_id);
    $transaction->instance_id = $payment_method['instance_id'];

    $transaction->amount = $charge['amount'];
    $transaction->currency_code = $charge['currency_code'];
    $transaction->payload[REQUEST_TIME] = $respondCharge->getCreated();
    
    $default_currency = commerce_default_currency();
    $amountCents = number_format(commerce_currency_convert($charge['amount'], $charge['currency_code'], $default_currency), 0, '', '');
    $toValidate = array(
        'currency' => $default_currency,
        'value' => $amountCents,
        'trackId' => $order->order_id,
        );
    $Api = CheckoutApi_Api::getApi(array('mode' => $config['mode']));
    $validateRequest = $Api::validateRequest($toValidate,$respondCharge);

    if ($respondCharge->isValid()) {
      if (preg_match('/^1[0-9]+$/', $respondCharge->getResponseCode())) {
        
        if($validateRequest['status']){  
          $transaction->message = 'Your transaction has been successfully authorized with transaction id : ' . $respondCharge->getId();
        }
        else {  
          $transaction->message = '';
          foreach($validateRequest['message'] as $errormessage){
            $transaction->message .= $errormessage . '. ';
          }
          $transaction->message .= 'Please contact your merchant.';
        }

        $transaction->status = COMMERCE_PAYMENT_STATUS_PENDING;
        commerce_payment_transaction_save($transaction);
        return true;
      }
      $transaction->status = COMMERCE_PAYMENT_STATUS_FAILURE;
      drupal_set_message(t('We could not process your card. Please verify your information again or try a different card.'), 'error');
      drupal_set_message(check_plain($respondCharge->getMessage()), 'error');
      $transaction->message = $respondCharge->getRawRespond();
      commerce_payment_transaction_save($transaction);
      return false;
    }
    else {
      $transaction->status = COMMERCE_PAYMENT_STATUS_FAILURE;
      $transaction->message = $respondCharge->getRawRespond();

      drupal_set_message(t('We received the following error processing your card. Please verify your information again or try a different card.'), 'error');
      drupal_set_message(check_plain($respondCharge->getExceptionState()->getErrorMessage()), 'error');
      commerce_payment_transaction_save($transaction);
      return false;
    }
  }

  protected function _createCharge($config) {
    $Api = CheckoutApi_Api::getApi(array('mode' => $config['mode']));
    return $Api->createCharge($config);
  }

  protected function _captureConfig($action) {
    $to_return['postedParam'] = array(
        'autoCapture' => CheckoutApi_Client_Constant::AUTOCAPUTURE_CAPTURE,
        'autoCapTime' => $action['settings']['autocaptime']
    );

    return $to_return;
  }

  protected function _authorizeConfig() {
    $to_return['postedParam'] = array(
        'autoCapture' => CheckoutApi_Client_Constant::AUTOCAPUTURE_AUTH,
        'autoCapTime' => 0
    );
    return $to_return;
  }

  public function getExtraInit($order,$payment_method) {
    return null;
  }
}
