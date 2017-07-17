<?php
/**
 * @author    G2A Team
 * @copyright Copyright (c) 2016 G2A.COM
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
defined('_JEXEC') or die('Restricted access');

require_once 'G2APayClient.php';
/** @var $client G2APayClient */
$client = new G2APayClient($this->getPaymentUrl() . 'createQuote');
$client->setMethod(G2APayClient::METHOD_POST);
$response = $client->request($this->vars);
    try {
        if (empty($response['token'])) {
            throw new Exception('Empty Token');
        }
        header('Location: ' . $this->getPaymentUrl() . 'gateway?token=' . $response['token']);
    } catch (Exception $ex) {
        $this->app->enqueueMessage('Some error occurs processing payment', 'error');
    }
