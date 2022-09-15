<?php
/**
 * COD Payment Module
 *
 * @package paymentMethod
 * @copyright Copyright 2003-2016 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */
  class weldpay {
    var $code, $title, $description, $enabled, $form_action_url;

// class constructor
    function __construct() {
      global $order, $current_page_base;

      $this->code = 'weldpay';
      $this->title = MODULE_PAYMENT_WELDPAY_TEXT_TITLE;
      $this->description = MODULE_PAYMENT_WELDPAY_TEXT_DESCRIPTION;
      $this->sort_order = MODULE_PAYMENT_WELDPAY_SORT_ORDER;
      $this->enabled = ((MODULE_PAYMENT_WELDPAY_STATUS == 'True') ? true : false);

      if ((int)MODULE_PAYMENT_WELDPAY_PENDING_ORDER_STATUS_ID > 0) {
        $this->order_status = MODULE_PAYMENT_WELDPAY_PENDING_ORDER_STATUS_ID;
      }

      if (is_object($order)) $this->update_status();
      
    }

// class methods
    function update_status() {
      global $order, $db;

      if ($this->enabled && (int)MODULE_PAYMENT_WELDPAY_ZONE > 0 && isset($order->delivery['country']['id'])) {
        $check_flag = false;
        $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_WELDPAY_ZONE . "' and zone_country_id = '" . (int)$order->delivery['country']['id'] . "' order by zone_id");
        while (!$check->EOF) {
          if ($check->fields['zone_id'] < 1) {
            $check_flag = true;
            break;
          } elseif ($check->fields['zone_id'] == $order->delivery['zone_id']) {
            $check_flag = true;
            break;
          }
          $check->MoveNext();
        }

        if ($check_flag == false) {
          $this->enabled = false;
        }
      }

// disable the module if the order only contains virtual products
      if ($this->enabled == true) {
        if ($order->content_type != 'physical') {
          $this->enabled = false;
        }
      }

      // other status checks?
      if ($this->enabled) {
        // other checks here
      }
    }

    function javascript_validation() {
      return false;
    }

    function selection() {
      return array('id' => $this->code,
                   'module' => '<img style="max-width:200px;width:100%;vertical-align:middle;" src="'.DIR_WS_IMAGES.'/banners/weldpay_logo.jpg"></br>' . $this->title);
    }

    function pre_confirmation_check() {
      return false;
    }

    function confirmation() {
      return false;
    }

    function process_button() {        
      return false;
    }

    function before_process() { 
        
        global $order, $order_totals, $order_total_modules, $db, $insert_id, $messageStack;
        
        $code = substr(str_shuffle(MD5(microtime())), 0, 12);
                
        //  save in weldpay table
        $sql = "INSERT INTO ".TABLE_WELDPAY."
                SET code = '".$code."',
                order_data = '".addslashes(serialize($order))."',
                order_totals = '".addslashes(serialize($order_totals))."',
                order_total_modules = '".addslashes(serialize($order_total_modules))."'";
        $db->execute($sql);
        //echo '<pre>'; print_r($order); echo '</pre>'; exit();
      foreach ($order->products as $item) {
        $weldpay_item = array(
          'Name' => $item['name'],
          'Notes' => $item['qty'],
          'Amount' => $item['final_price'] * $item['qty']
        );
        $weldpay_items[] = json_encode($weldpay_item);
      }
      
      //$order_totals = $order_total_modules->process();
      //echo '<pre>'; print_r($order_totals); echo '</pre>'; exit();
      foreach ($order_totals as $value) {
        if (in_array($value['code'], array('ot_tax', 'ot_surcharge'))) {
            if ($value['value'] > 0) {
                $weldpay_item = array(
                  'Name' => strip_tags($value['title']),
                  'Notes' => '',
                  'Amount' => $value['value']
                );
                $weldpay_items[] = json_encode($weldpay_item);
            }
        } elseif ($value['code'] == 'ot_coupon') {
            if ($value['value'] > 0) {
                $weldpay_item = array(
                  'Name' => strip_tags($value['title']),
                  'Notes' => '',
                  'Amount' => '-'.$value['value']
                );
                $weldpay_items[] = json_encode($weldpay_item);
            }
        }
      }

      //  get order id
      $sql = "SELECT orders_id FROM ".TABLE_ORDERS."
              ORDER BY orders_id DESC
              LIMIT 1";
      $max_order_res = $db->execute($sql);
      $max_order_id = $max_order_res->fields['orders_id'].'-'.date('Ymdhis');
      //echo $max_order_id; exit();
      
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, "https://payments.weldpay.it/api/1.0/gateway/generate-transaction");
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($ch, CURLOPT_HEADER, FALSE);
      curl_setopt($ch, CURLOPT_POST, TRUE);
      curl_setopt($ch, CURLOPT_POSTFIELDS, "{
        \"Buyer\": {
          \"Firstname\": \"".$order->customer['firstname']."\",
          \"Lastname\": \"".$order->customer['lastname']."\",
          \"TaxCode\": \"".$tax_name."\",
          \"Email\": \"".$order->customer['email_address']."\",
        },
        \"OrderId\": \"".$max_order_id."\",
        \"Items\": [".implode(',', $weldpay_items)."],
        \"ShippingItems\": [
          {
            \"Name\": \"".strip_tags($order->info['shipping_method'])."\",
            \"Notes\": null,
            \"Amount\": ".$order->info['shipping_cost']."
          }
        ],
        \"SuccessUrl\": \"".HTTP_SERVER.DIR_WS_CATALOG."weldpay.php?weldpay_code=".$code."\",
        \"CancelUrl\": \"".zen_href_link(FILENAME_SHOPPING_CART)."\",
        \"ServerNotificationUrl\": \"".HTTP_SERVER.DIR_WS_CATALOG."weldpay.php?action=notify&order_id=".$insert_id."\"
      }");
      
      //  zen_href_link(FILENAME_CHECKOUT_SUCCESS)
      //  HTTP_SERVER.DIR_WS_CATALOG."weldpay.php?action=success&order_id=".$insert_id
      
      curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "Authorization: Basic ".base64_encode(MODULE_PAYMENT_WELDPAY_CLIENT_ID.":".MODULE_PAYMENT_WELDPAY_CLIENT_SECRET).""
      ));
      $response = curl_exec($ch);
      //echo '<pre>'; print_r($response); echo '</pre>'; exit();
      curl_close($ch);
      
      if (strpos($response, '500') > 0 || strpos($response, '404') > 0) {
        $messageStack->add_session('checkout_payment', $response, 'error');
        zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT));
      }
      //$this->form_action_url = $response;
      header("Location: ".$response);
      die();
        
        //return false;
    }

    function after_process() {
        return false;
    }
    
    function after_order_create() {          
      return false;
    }

    function get_error() {
      return false;
    }

    function check() {
      global $db;
      if (!isset($this->_check)) {
        $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_WELDPAY_STATUS'");
        $this->_check = $check_query->RecordCount();
      }
      return $this->_check;
    }

    function install() {
      global $db, $messageStack;
      if (defined('MODULE_PAYMENT_WELDPAY_STATUS')) {
        $messageStack->add_session('Weldpay module already installed.', 'error');
        zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=weldpay', 'NONSSL'));
        return 'failed';
      }
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Weldpay Module', 'MODULE_PAYMENT_WELDPAY_STATUS', 'True', 'Do you want to accept Weldpay payments?', '6', '1', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Payment Zone', 'MODULE_PAYMENT_WELDPAY_ZONE', '0', 'If a zone is selected, only enable this payment method for that zone.', '6', '2', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_WELDPAY_SORT_ORDER', '3', 'Sort order of display. Lowest is displayed first.', '6', '0', now())");
      
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Client ID', 'MODULE_PAYMENT_WELDPAY_CLIENT_ID', '0', 'Client ID', '', '0', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Client secret', 'MODULE_PAYMENT_WELDPAY_CLIENT_SECRET', '0', 'Client secret', '', '4', now())");
      
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Order Status', 'MODULE_PAYMENT_WELDPAY_PENDING_ORDER_STATUS_ID', '1', 'Set the status of orders before user make payment', '6', '5', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Order Status', 'MODULE_PAYMENT_WELDPAY_ORDER_STATUS_ID', '0', 'Set the status of orders made with this payment module to this value', '6', '6', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
      
      if (!defined('TABLE_WELDPAY')) define('TABLE_WELDPAY', DB_PREFIX.'weldpay');
      $db->execute("DROP TABLE IF EXISTS `".TABLE_WELDPAY."`;");
      $db->execute("CREATE TABLE IF NOT EXISTS `".TABLE_WELDPAY."` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `order_id` int(11) NOT NULL DEFAULT '0',
  `order_data` text NOT NULL,
  `order_totals` text NOT NULL,
  `order_total_modules` text NOT NULL,
  UNIQUE KEY `idx_id` (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;");
      
   }

    function remove() {
      global $db;
      $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
      
      if (!defined('TABLE_WELDPAY')) define('TABLE_WELDPAY', DB_PREFIX.'weldpay');
      $db->Execute("DROP TABLE IF EXISTS ".TABLE_WELDPAY.";");
    }

    function keys() {
      return array('MODULE_PAYMENT_WELDPAY_STATUS', 'MODULE_PAYMENT_WELDPAY_ZONE', 'MODULE_PAYMENT_WELDPAY_ORDER_STATUS_ID', 'MODULE_PAYMENT_WELDPAY_SORT_ORDER', 'MODULE_PAYMENT_WELDPAY_CLIENT_ID', 'MODULE_PAYMENT_WELDPAY_CLIENT_SECRET', 'MODULE_PAYMENT_WELDPAY_PENDING_ORDER_STATUS_ID');
    }
  }
