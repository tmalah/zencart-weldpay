<?php

include_once('includes/application_top.php');

//echo 'GET:<pre>'; print_r($_GET); echo '</pre>'; //exit();

//echo 'POST:<pre>'; print_r($_POST); echo '</pre>'; //exit();

if (isset($_GET['weldpay_code']) && $_GET['weldpay_code'] != '') {
        
        $code = $_GET['weldpay_code'];
        
        //  get order id
        $sql = "SELECT * FROM ".TABLE_WELDPAY."
                WHERE code = '".$code."'";

        $weldpay_res = $db->execute($sql);
        
        if ($weldpay_res->RecordCount() > 0) {
            
            $_SESSION['payment'] = 'weldpay';
            
            require(DIR_WS_CLASSES . 'payment.php');
            $payment_modules = new payment($_SESSION['payment']);

            require_once(DIR_WS_CLASSES.'order.php');
            require_once(DIR_WS_CLASSES . 'order_total.php');
            
            include_once(DIR_WS_LANGUAGES.$_SESSION['language'].'/checkout_process.php');
            
            $order = unserialize($weldpay_res->fields['order_data']);
            $order_totals = unserialize($weldpay_res->fields['order_totals']);
            $order_total_modules = unserialize($weldpay_res->fields['order_total_modules']);
//echo '<pre>'; print_r($order); echo '</pre>'; exit();
            $insert_id = $order->create(unserialize($weldpay_res->fields['order_totals']), 2);

            // store the product info to the order
            $order->create_add_products($insert_id);

            //send email notifications
            $order->send_order_email($insert_id, 2);
            
            if (MODULE_PAYMENT_WELDPAY_ORDER_STATUS_ID == 0) {
                $order_status = DEFAULT_ORDERS_STATUS_ID;
            } else {
                $order_status = MODULE_PAYMENT_WELDPAY_ORDER_STATUS_ID;
            }
        
            //  set order status
            $sql = "UPDATE ".TABLE_ORDERS."
                    SET orders_status = ".$order_status."
                    WHERE orders_id = ".$insert_id;
            $db->execute($sql);
        
            //  set order status history
            $commentString = 'Weldpay code: '.$code;
            
            $sql_data_array= array(array('fieldName' => 'orders_id', 'value' => $insert_id, 'type' => 'integer'),
                               array('fieldName' => 'orders_status_id', 'value' => $order_status, 'type' => 'integer'),
                               array('fieldName' => 'date_added', 'value' => 'now()', 'type' => 'noquotestring'),
                               array('fieldName' => 'customer_notified', 'value' => 0, 'type' => 'integer'),
                               array('fieldName' => 'comments', 'value' => $commentString, 'type' => 'string'));
            $db->perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
        }
        
        
        $_SESSION['cart']->reset(true);

    // unregister session variables used during checkout
      unset($_SESSION['sendto']);
      unset($_SESSION['billto']);
      unset($_SESSION['shipping']);
      unset($_SESSION['payment']);
      unset($_SESSION['comments']);
      //$order_total_modules->clear_posts();//ICW ADDED FOR CREDIT CLASS SYSTEM
    
      // This should be before the zen_redirect:
      $zco_notifier->notify('NOTIFY_HEADER_END_CHECKOUT_PROCESS');
    
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_SUCCESS, (isset($_GET['action']) && $_GET['action'] == 'confirm' ? 'action=confirm' : ''), 'SSL'));
        
}

?>