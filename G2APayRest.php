<?php
/**
 * @author    G2A Team
 * @copyright Copyright (c) 2016 G2A.COM
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
require_once 'g2apay.php';
require_once 'G2APayClient.php';

class G2APayRest
{
    /**
     * @var array REST base urls grouped by environment
     */
    protected static $REST_BASE_URLS = array(
        'production' => 'https://pay.g2a.com/rest',
        'test'       => 'https://www.test.pay.g2a.com/rest',
    );
    protected $transactionId;
    protected $pluginSettings;

    /**
     * G2APayRest constructor.
     * @param $transactionId G2A Pay transaction Id
     * @param $pluginSettings G2A Pay plugin settings
     */
    public function __construct($transactionId, $pluginSettings)
    {
        $this->transactionId  = $transactionId;
        $this->pluginSettings = $pluginSettings;
    }

    /**
     * @param $order
     * @param $amount
     * @return bool
     */
    public function refundOrder($order, $amount)
    {
        try {
            $amount = plgHikashoppaymentG2apay::getValidAmount($amount);

            $data = array(
                    'action' => 'refund',
                    'amount' => $amount,
                    'hash'   => $this->generateRefundHash($order, $amount),
            );

            $path   = sprintf('transactions/%s', $this->transactionId);
            $url    = $this->getRestUrl($path);
            $client = $this->createRestClient($url, G2APayClient::METHOD_PUT);

            $result = $client->request($data);

            return is_array($result) && isset($result['status']) && strcasecmp($result['status'], 'ok') === 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param $url
     * @param $method
     * @return G2APayClient
     */
    protected function createRestClient($url, $method)
    {
        $client = new G2APayClient($url);
        $client->setMethod($method);
        $client->addHeader('Authorization', $this->pluginSettings->apihash . ';' . $this->getAuthorizationHash());

        return $client;
    }

    /**
     * @param $order
     * @param $amount
     * @return mixed
     */
    protected function generateRefundHash($order, $amount)
    {
        $string = $this->transactionId . $order->order_id
            . plgHikashoppaymentG2apay::getValidAmount($order->order_full_price) . $amount
            . $this->pluginSettings->apisecret;

        return hash('sha256', $string);
    }

    /**
     * @param string $path
     * @return string
     */
    public function getRestUrl($path = '')
    {
        $path     = ltrim($path, '/');
        $base_url = self::$REST_BASE_URLS[$this->pluginSettings->g2apay_mode];

        return $base_url . '/' . $path;
    }

    /**
     * Returns generated authorization hash.
     *
     * @return string
     */
    public function getAuthorizationHash()
    {
        $string = $this->pluginSettings->apihash . $this->pluginSettings->merchantemail
            . $this->pluginSettings->apisecret;

        return hash('sha256', $string);
    }
}
