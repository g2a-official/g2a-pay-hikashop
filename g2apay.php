<?php
/**
 * @author    G2A Team
 * @copyright Copyright (c) 2016 G2A.COM
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
defined('_JEXEC') or die('Restricted access');

require_once 'G2APayClient.php';
require_once 'G2APayRest.php';

class plgHikashoppaymentG2apay extends hikashopPaymentPlugin
{
    const PRODUCTION                       = 'https://checkout.pay.g2a.com/index/';
    const SANDBOX                          = 'https://checkout.test.pay.g2a.com/index/';
    const STATUS_CANCELED                  = 'canceled';
    const STATUS_COMPLETE                  = 'complete';
    const STATUS_REFUNDED                  = 'refunded';
    const STATUS_PARTIAL_REFUNDED          = 'partial_refunded';
    const STATUS_OK                        = 'OK';
    const STATUS_NEW                       = 'new';
    const G2APAY_PRODUCTION                = 'production';
    const LOCALHOST                        = '127.0.0.1';
    const G2APAY_LOGO_NAME                 = 'g2apay.png';
    const G2APAY_IPN_TABLE_NAME            = 'g2apay_ipn';
    const G2APAY_REFUND_HISTORY_TABLE_NAME = 'g2apay_refund_history';

    public $multiple = true;
    public $name     = 'g2apay';

    public $pluginConfig = array(
        'apihash'       => array('API Hash', 'input'),
        'apisecret'     => array('API Secret', 'input'),
        'merchantemail' => array('Merchant Email', 'input'),
        'g2apay_mode'   => array('Environment', 'list',
            array(
                'test'       => 'SANDBOX',
                'production' => 'PRODUCTION',
            ),
        ),
        'notify_url'              => array('NOTIFY_URL_DEFINE', 'html', ''),
        'invalid_status'          => array('INVALID_STATUS', 'orderstatus'),
        'verified_status'         => array('VERIFIED_STATUS', 'orderstatus'),
        'refunded_status'         => array('Refund status', 'orderstatus'),
        'partial_refunded_status' => array('Partial refund status', 'orderstatus'),

    );

    /**
     * plgHikashoppaymentG2apay constructor.
     * @param $subject
     * @param $config
     */
    public function __construct(&$subject, $config)
    {
        $this->setNotifyUrl();
        parent::__construct($subject, $config);
    }

    public function setNotifyUrl()
    {
        $notiify_url_params = array(
            'option'        => 'com_hikashop',
            'ctrl'          => 'checkout',
            'task'          => 'notify',
            'notif_payment' => $this->name,
            'tmpl'          => 'component',
        );
        $this->pluginConfig['notify_url'][2] = HIKASHOP_LIVE . 'index.php?'
            . http_build_query($notiify_url_params, '', '&amp;');
    }

    /**
     * @param $order
     * @param $do
     * @return bool
     */
    public function onBeforeOrderCreate(&$order, &$do)
    {
        if (parent::onBeforeOrderCreate($order, $do)) {
            return true;
        }
        if (!$this->isPaymentParametersValidate()) {
            $this->app->enqueueMessage('Please check your &quot;G2A Pay&quot; plugin configuration');
            $do = false;
        }
    }

    /**
     * @param $order
     * @param $methods
     * @param $method_id
     * @return bool
     */
    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);
        if (!$this->isPaymentParametersValidate()) {
            return false;
        }
        $this->vars = $this->prepareVarsArray($order);

        return $this->showPage('end');
    }

    /**
     * @param $history
     * @throws Exception
     */
    public function onHistoryDisplay(&$history)
    {
        $output    = '';
        $jInput    = JFactory::getApplication()->input;
        $orderId   = $jInput->get('cid');
        $orderId   = $orderId[0];
        $orderData = $this->loadOrderRelatedData($orderId);
        if ($orderData->order_payment_method !== $this->name) {
            return;
        }
        if ($amount = $jInput->getString('g2apay_refund_amount')) {
            $amount = str_replace(',', '.', $amount);
            $this->proceedRefund($orderData, self::getValidAmount($amount));
        }
        $fieldsetOpen = '<div class="row-fluid"><fieldset class="hika_field adminform" id="hikashop_order_field_general">	
            <legend>G2A Pay Refund</legend>';
        $fieldsetClose = '</fieldset></div>';
        $refundForm    = '<form method="post" action="' . hikashop_currentURL() . '">
		            <label for="g2apay_refund_amount">Amount to refund: </label>
		            <input type="text" id="g2apay_refund_amount" name="g2apay_refund_amount" required/><br />
                    <input style="margin-bottom: 5px" type="submit" value="Proceed Refund">
                </form>';
        $output .= $fieldsetOpen;
        $orderRefundHistoryTable = $this->createRefundHistoryTable($orderId);
        if ($orderData->order_status === self::STATUS_REFUNDED && empty($orderRefundHistoryTable)) {
            return;
        }
        if ($orderData->order_status !== self::STATUS_REFUNDED && $this->getTransactionId($orderData->order_id)) {
            $output .= $refundForm;
        }
        $output .= $orderRefundHistoryTable;
        $output .= $fieldsetClose;
        echo $output;
    }

    /**
     * @param $orderId
     * @return string
     */
    public function createRefundHistoryTable($orderId)
    {
        $refundHistoryTable = '';

        $db    = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*');
        $query->from($db->quoteName(self::G2APAY_REFUND_HISTORY_TABLE_NAME));
        $query->where($db->quoteName('order_id') . ' = ' . $orderId);
        $db->setQuery($query);
        $results = null;
        $results = $db->loadAssocList();

        if (!$results) {
            return $refundHistoryTable;
        }

        $refundHistoryTable =
            '<table style="width: 400px" class="hika_listing hika_table table table-striped table-hover">
                <caption><strong>Refund History: </strong></caption>
	            <thead>
		            <tr>
                        <th class="title">Id</th>
                        <th class="title">Refund amount</th>
                        <th class="title">Refund date</th>
		            </tr>
	            </thead>
	        <tbody>';

        foreach ($results as $result) {
            $refundHistoryTable .= '<tr>
                    <td>' . $result['id'] . '</td>
                    <td>' . $result['refunded_amount'] . '</td>
                    <td>' . $result['refund_date'] . '</td>
		        </tr>';
        }

        $refundHistoryTable .= '</tbody></table>';

        return $refundHistoryTable;
    }

    /**
     * @param $orderData
     * @param $amount
     */
    private function proceedRefund($orderData, $amount)
    {
        try {
            $transactionId = '';
            if ($amount <= 0) {
                throw new Exception('Invalid amount. ');
            }
            $transactionId = $this->getTransactionId($orderData->order_id);
            if (!$transactionId) {
                throw new Exception('Can not proceed with refund. Order payment not confirmed by IPN');
            }
            if ($amount > self::getValidAmount($orderData->order_full_price)) {
                throw new Exception('Refund amount can not be greater than order total price');
            }
            $restClient = new G2APayRest($transactionId, $this->payment_params);
            if (!$restClient->refundOrder($orderData, $amount)) {
                throw new Exception('Some error occurred processing online refund for amount: ' . $amount);
            }
            $this->app->enqueueMessage(JText::_('Online refund successfully executed for amount: '
                . $amount), 'success');
            $this->app->redirect(hikashop_currentURL());
        } catch (Exception $e) {
            $this->app->enqueueMessage(JText::_($e->getMessage()), 'error');
            $this->app->redirect(hikashop_currentURL());
        }
    }

    /**
     * @param $orderId
     * @return string|bool
     */
    public function getTransactionId($orderId)
    {
        $db    = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select($db->quoteName(array('transaction_id')));
        $query->from($db->quoteName(self::G2APAY_IPN_TABLE_NAME));
        $query->where($db->quoteName('order_id') . ' = ' . $orderId);
        $db->setQuery($query);
        $result = $db->loadAssoc();

        return isset($result['transaction_id']) ? $result['transaction_id'] : false;
    }

    /**
     * @param $statuses
     * @return string
     */
    public function onPaymentNotification(&$statuses)
    {
        $vars    = $this->createArrayOfRequestParams();
        $orderId = (int) $vars['userOrderId'];
        $orderDb = $this->loadOrderRelatedData($orderId);

        if (!$this->isRequestValid($vars, $orderDb)) {
            return $this->processRequest($orderId, $this->payment_params->invalid_status);
        }
        if (!$this->isCalculatedHashMatch($vars)) {
            return $this->processRequest($orderId, $this->payment_params->pending_status);
        }
        if (isset($vars['status']) && $vars['status'] === self::STATUS_COMPLETE) {
            $this->saveIpn($orderId, $vars['transactionId']);

            return $this->processRequest($orderId, $this->payment_params->verified_status);
        }
        if (isset($vars['status']) && $vars['status'] === self::STATUS_REFUNDED) {
            $this->saveRefund($orderId, self::getValidAmount($vars['refundedAmount']));

            return $this->processRequest($orderId, $this->payment_params->refunded_status);
        }
        if (isset($vars['status']) && $vars['status'] === self::STATUS_PARTIAL_REFUNDED) {
            $this->saveRefund($orderId, self::getValidAmount($vars['refundedAmount']));

            return $this->processRequest($orderId, $this->payment_params->partial_refunded_status);
        }
    }

    /**
     * @param $orderId
     * @param $transactionId
     */
    public function saveIpn($orderId, $transactionId)
    {
        $db      = JFactory::getDbo();
        $columns = array('order_id', 'transaction_id');
        $values  = array($db->quote($orderId), $db->quote($transactionId));
        $query   = $db->getQuery(true);
        $query->insert($db->quoteName(self::G2APAY_IPN_TABLE_NAME))
            ->columns($db->quoteName($columns))
            ->values(implode(',', $values));
        $db->setQuery($query)->execute();
    }

    /**
     * @param $orderId
     * @param $refundedAmount
     */
    public function saveRefund($orderId, $refundedAmount)
    {
        $db      = JFactory::getDbo();
        $columns = array('order_id', 'refunded_amount');
        $values  = array($db->quote($orderId), $db->quote($refundedAmount));
        $query   = $db->getQuery(true);
        $query->insert($db->quoteName(self::G2APAY_REFUND_HISTORY_TABLE_NAME))
            ->columns($db->quoteName($columns))
            ->values(implode(',', $values));
        $db->setQuery($query)->execute();
    }

    /**
     * @param $orderId
     * @param $status
     * @return mixed
     */
    private function processRequest($orderId, $status)
    {
        $this->modifyOrder($orderId, $status, true, true);

        return true;
    }

    /**
     * Set default payment settings values.
     *
     * @param $element
     */
    public function getPaymentDefaultValues(&$element)
    {
        $element->payment_name                    = 'G2A Pay';
        $element->payment_description             = 'Easily integrate 100+ global and local payment methods with 
                                                     all-in-one solution.';
        $element->payment_images                  = 'g2apay';
        $element->payment_params->invalid_status  = 'cancelled';
        $element->payment_params->pending_status  = 'created';
        $element->payment_params->verified_status = 'confirmed';
    }

    /**
     * Moving image is necessary. For more details read
     * http://www.hikashop.com/forum/development/878700-uninstall-custom-payment-plugin-image.html.
     * @param $element
     */
    public function onPaymentConfiguration(&$element)
    {
        $db  = JFactory::getDbo();
        $sql = 'CREATE TABLE IF NOT EXISTS ' . self::G2APAY_IPN_TABLE_NAME . ' (
        id INT NOT NULL AUTO_INCREMENT,
        order_id INT NOT NULL,
        transaction_id varchar(70) NOT NULL,
        PRIMARY KEY (id)
        )';
        $db->setQuery($sql)->execute();
        $sql = 'CREATE TABLE IF NOT EXISTS ' . self::G2APAY_REFUND_HISTORY_TABLE_NAME . ' (
        id INT NOT NULL AUTO_INCREMENT,
        order_id INT NOT NULL,
        refunded_amount VARCHAR(15) NOT NULL,
        refund_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ,
        PRIMARY KEY (id)
        )';
        $db->setQuery($sql)->execute();
        $imagePath = HIKASHOP_MEDIA . 'images/';
        $this->moveImage($imagePath);
        parent::onPaymentConfiguration($element);
    }

    /**
     *  Move image from folder /image/payment/g2apay/ to /image/payment.
     *
     * @param $imagePath
     */
    private function moveImage($imagePath)
    {
        $src         = $imagePath . 'payment' . DS . 'g2apay' . DS . self::G2APAY_LOGO_NAME;
        $destination = $imagePath . 'payment' . DS . self::G2APAY_LOGO_NAME;
        JFile::copy($src, $destination, null, true);
    }

    /**
     * Check if required config parameters are set correctly.
     * @return bool
     */
    private function isPaymentParametersValidate()
    {
        if (!$this->payment_params->apihash || !$this->payment_params->apisecret
            || !$this->payment_params->merchantemail) {
            $this->app->enqueueMessage(JText::_('INCOMPLETE_CONFIG'), 'error');

            return false;
        }

        if (!filter_var($this->payment_params->merchantemail, FILTER_VALIDATE_EMAIL)) {
            $this->app->enqueueMessage(JText::_('BAD_EMAIL'), 'error');

            return false;
        }

        return true;
    }

    /**
     * Return price in correct format.
     *
     * @param $amount
     * @return float
     */
    public static function getValidAmount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * This method prepare array which is send to G2A Pay based on order, payment configuration
     * and customer address.
     *
     * @param $order
     * @return array
     */
    private function prepareVarsArray($order)
    {
        $return_url_params = array(
            'option'   => 'com_hikashop',
            'ctrl'     => 'checkout',
            'task'     => 'after_end',
            'order_id' => $order->order_id . $this->url_itemid,
        );
        $cancel_url_params = array(
            'option'   => 'com_hikashop',
            'ctrl'     => 'order',
            'task'     => 'cancel_order',
            'order_id' => $order->order_id . $this->url_itemid,
        );
        $return_url  =  $this->createUrl($return_url_params);
        $cancel_url  = $this->createUrl($cancel_url_params);
        $vars        = array(
            'api_hash'    => $this->payment_params->apihash,
            'hash'        => $this->calculateHash($order),
            'order_id'    => (string) $order->order_id,
            'amount'      => (string) self::getValidAmount($order->cart->full_total->prices[0]->price_value_with_tax),
            'currency'    => $this->currency->currency_code,
            'url_failure' => $cancel_url,
            'url_ok'      => $return_url,
            'email'       => $order->cart->customer->user_email,
            'addresses'   => $this->generateAddressesArray($order),
            'items'       => $this->getItemsArray($order),
        );

        return $vars;
    }

    /**
     * @param $order
     * @return array
     */
    private function generateAddressesArray($order)
    {
        $addresses = array();
        $billing_address = $order->cart->billing_address;
        $shipping_address = $order->cart->shipping_address;

        $addresses['billing'] = array(
            'firstname' => $billing_address->address_firstname,
            'lastname'  => $billing_address->address_lastname,
            'line_1'    => $billing_address->address_street,
            'line_2'    => $billing_address->address_street2,
            'zip_code'  => $billing_address->address_post_code,
            'city'      => $billing_address->address_city,
            'company'   => is_null($billing_address->address_company) ? '' : $billing_address->address_company,
            'county'    => $billing_address->address_state->zone_name,
            'country'   => $billing_address->address_country->zone_code_2,
        );
        $addresses['shipping'] = array(
            'firstname' => $shipping_address->address_firstname,
            'lastname'  => $shipping_address->address_lastname,
            'line_1'    => $shipping_address->address_street,
            'line_2'    => $shipping_address->address_street2,
            'zip_code'  => $shipping_address->address_post_code,
            'company'   => is_null($shipping_address->address_company) ? '' : $shipping_address->address_company,
            'city'      => $shipping_address->address_city,
            'county'    => $shipping_address->address_state->zone_name,
            'country'   => $shipping_address->address_country->zone_code_2,
        );

        return $addresses;
    }

    /**
     * @param $params
     * @return string
     */
    public function calculateHash($params)
    {
        if (!is_array($params) && $params instanceof stdClass) {
            $unhashedString = $params->order_id
                . self::getValidAmount($params->cart->full_total->prices[0]->price_value_with_tax)
                . $this->currency->currency_code . $this->payment_params->apisecret;
        } else {
            $unhashedString = $params['transactionId'] . $params['userOrderId'] . $params['amount']
                . $this->payment_params->apisecret;
        }

        return hash('sha256', $unhashedString);
    }

    /**
     * @param $order
     * @return array
     */
    public function getItemsArray($order)
    {
        $itemsInfo  = array();
        $url_params = array(
            'option'       => 'com_hikashop',
            'ctrl'         => 'product',
            'task'         => 'show',
            'product_id'   => '',
        );
        foreach ($order->cart->products as $orderItem) {
            $url_params['product_id'] = $orderItem->product_id;
            $itemsInfo[]              = array(
                'sku'    => $orderItem->product_id,
                'name'   => $orderItem->order_product_name,
                'amount' => self::getValidAmount(($orderItem->order_product_price + $orderItem->order_product_tax)
                            * $orderItem->order_product_quantity),
                'qty'    => (integer) $orderItem->order_product_quantity,
                'id'     => $orderItem->product_id,
                'price'  => self::getValidAmount($orderItem->order_product_price + $orderItem->order_product_tax),
                'url'    => $this->createUrl($url_params),
            );
        }
        //add shipping method to items array
        if ($order->order_shipping_price > 0) {
            $itemsInfo[] = array(
                'sku'    => $order->order_shipping_id,
                'name'   => $order->order_shipping_method,
                'amount' => self::getValidAmount($order->order_shipping_price),
                'qty'    => 1,
                'id'     => $order->order_shipping_id,
                'price'  => self::getValidAmount($order->order_shipping_price),
                'url'    => HIKASHOP_LIVE,
            );
        }

        if ($order->cart->coupon->discount_value > 0) {
            $itemsInfo[] = array(
                'sku'    => '1',
                'name'   => $order->order_discount_code,
                'amount' => -self::getValidAmount($order->cart->coupon->discount_value),
                'qty'    => 1,
                'id'     => '1',
                'price'  => -self::getValidAmount($order->cart->coupon->discount_value),
                'url'    => HIKASHOP_LIVE,
            );
        }

        return $itemsInfo;
    }

    /**
     * @return string
     */
    public function getPaymentUrl()
    {
        if ($this->payment_params->g2apay_mode === 'production') {
            return self::PRODUCTION;
        }

        return self::SANDBOX;
    }

    /**
     * Modify request from G2A Pay to array format.
     *
     * @return array
     */
    private function createArrayOfRequestParams()
    {
        $vars   = array();
        $filter = JFilterInput::getInstance();
        foreach ($_REQUEST as $key => $value) {
            $key        = $filter->clean($key);
            $value      = JRequest::getString($key);
            $vars[$key] = $value;
        }

        return $vars;
    }

    /**
     * @param $orderId
     * @return bool|null
     */
    private function loadOrderRelatedData($orderId)
    {
        $dbOrder = $this->getOrder($orderId);
        $this->loadPaymentParams($dbOrder);
        if (empty($this->payment_params)) {
            return false;
        }
        $this->loadOrderData($dbOrder);

        return $dbOrder;
    }

    /**
     * @param $vars
     * @param $orderDb
     * @return bool
     */
    private function isRequestValid($vars, $orderDb)
    {
        if ($_SERVER['REQUEST_METHOD'] !== G2APayClient::METHOD_POST) {
            $this->statusMessage = 'Invalid request method';

            return false;
        }
        if (!$this->comparePrices($vars, $orderDb)) {
            $this->statusMessage = 'Price does not match';

            return false;
        }
        if ($vars['status'] === self::STATUS_CANCELED) {
            $this->statusMessage = self::STATUS_CANCELED;

            return false;
        }
        if (!isset($vars['transactionId'], $vars['refundedAmount'])) {
            $this->statusMessage = 'Invalid request params';

            return false;
        }

        return true;
    }

    /**
     * @param $vars
     * @param $orderDb
     * @return bool
     */
    private function comparePrices($vars, $orderDb)
    {
        $price = self::getValidAmount($orderDb->order_full_price);
        if (isset($vars['amount']) && $vars['amount'] == $price) {
            return true;
        }

        return false;
    }

    /**
     * @param $vars
     * @return bool
     */
    private function isCalculatedHashMatch($vars)
    {
        if ($this->calculateHash($vars) !== $vars['hash']) {
            $this->statusMessage = 'Calculated hash does not match';

            return false;
        }

        return true;
    }

    /**
     * @param $httpParams
     * @return string
     */
    public function createUrl($httpParams)
    {
        return HIKASHOP_LIVE . 'index.php?' . http_build_query($httpParams);
    }
}
