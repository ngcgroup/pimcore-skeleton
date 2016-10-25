<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @category   Pimcore
 * @package    EcommerceFramework
 * @copyright  Copyright (c) 2009-2016 pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */


namespace OnlineShop\Framework\CheckoutManager;
use OnlineShop\Framework\Factory;
use OnlineShop\Plugin;

/**
 * Class \OnlineShop\Framework\CheckoutManager\CommitOrderProcessor
 */
class CommitOrderProcessor implements ICommitOrderProcessor {

    /**
     * @var string
     */
    protected $confirmationMail = "/emails/order-confirmation";

    /**
     * @param string $confirmationMail
     */
    public function setConfirmationMail($confirmationMail) {
        if(!empty($confirmationMail)) {
            $this->confirmationMail = $confirmationMail;
        }
    }

    /**
     * @param $paymentResponseParams
     * @param \OnlineShop\Framework\PaymentManager\Payment\IPayment $paymentProvider
     * @return \OnlineShop\Framework\PaymentManager\Status|\OnlineShop\Framework\PaymentManager\IStatus
     */
    protected function getPaymentStatus($paymentResponseParams, \OnlineShop\Framework\PaymentManager\Payment\IPayment $paymentProvider) {
        //since handle response can throw exceptions and commitOrderPayment must be executed,
        // this needs to be in a try-catch block
        try {
            $paymentStatus = $paymentProvider->handleResponse($paymentResponseParams);
        } catch(\Exception $e) {
            \Logger::err($e);

            //create payment status with error message and cancelled payment
            $paymentStatus = new \OnlineShop\Framework\PaymentManager\Status(
                $paymentResponseParams['orderIdent'], "unknown", "there was an error: " . $e->getMessage(), \OnlineShop\Framework\PaymentManager\IStatus::STATUS_CANCELLED
            );
        }
        return $paymentStatus;
    }

    /**
     * @param $paymentResponseParams
     * @param \OnlineShop\Framework\PaymentManager\Payment\IPayment $paymentProvider
     * @return \OnlineShop\Framework\Model\AbstractOrder
     * @throws \Exception
     */
    public function handlePaymentResponseAndCommitOrderPayment($paymentResponseParams, \OnlineShop\Framework\PaymentManager\Payment\IPayment $paymentProvider) {

        //check if order is already committed and payment information with same internal payment id has same state
        //if so, do nothing and return order
        if($committedOrder = $this->committedOrderWithSamePaymentExists($paymentResponseParams, $paymentProvider)) {
            return $committedOrder;
        }

        $paymentStatus = $this->getPaymentStatus($paymentResponseParams, $paymentProvider);
        return $this->commitOrderPayment($paymentStatus, $paymentProvider);
    }

    /**
     * check if order is already committed and payment information with same internal payment id has same state
     *
     * @param array|\OnlineShop\Framework\PaymentManager\IStatus $paymentResponseParams
     * @param \OnlineShop\Framework\PaymentManager\Payment\IPayment $paymentProvider
     * @return null|\OnlineShop\Framework\Model\AbstractOrder
     * @throws \Exception
     * @throws \OnlineShop\Framework\Exception\UnsupportedException
     */
    public function committedOrderWithSamePaymentExists($paymentResponseParams, \OnlineShop\Framework\PaymentManager\Payment\IPayment $paymentProvider) {

        if(!$paymentResponseParams instanceof \OnlineShop\Framework\PaymentManager\IStatus) {
            $paymentStatus = $this->getPaymentStatus($paymentResponseParams, $paymentProvider);
        } else {
            $paymentStatus = $paymentResponseParams;
        }

        $orderManager = \OnlineShop\Framework\Factory::getInstance()->getOrderManager();
        $order = $orderManager->getOrderByPaymentStatus($paymentStatus);

        if($order && $order->getOrderState() == $order::ORDER_STATE_COMMITTED) {
            $paymentInformationCollection = $order->getPaymentInfo();
            if($paymentInformationCollection) {
                foreach($paymentInformationCollection as $paymentInfo) {
                    if($paymentInfo->getInternalPaymentId() == $paymentStatus->getInternalPaymentId()) {
                        if($paymentInfo->getPaymentState() == $paymentStatus->getStatus()) {
                            return $order;
                        } else {
                            $message = "Payment state of order " . $order->getId() . " does not match with new request!";
                            \Logger::error($message);
                            throw new \Exception($message);
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * @param \OnlineShop\Framework\PaymentManager\IStatus $paymentStatus
     * @param \OnlineShop\Framework\PaymentManager\Payment\IPayment $paymentProvider
     * @return \OnlineShop\Framework\Model\AbstractOrder
     * @throws \Exception
     * @throws \OnlineShop\Framework\Exception\UnsupportedException
     */
    public function commitOrderPayment(\OnlineShop\Framework\PaymentManager\IStatus $paymentStatus, \OnlineShop\Framework\PaymentManager\Payment\IPayment $paymentProvider) {

        //check if order is already committed and payment information with same internal payment id has same state
        //if so, do nothing and return order
        if($committedOrder = $this->committedOrderWithSamePaymentExists($paymentStatus, $paymentProvider)) {
            return $committedOrder;
        }

        $orderManager = \OnlineShop\Framework\Factory::getInstance()->getOrderManager();
        $order = $orderManager->getOrderByPaymentStatus($paymentStatus);

        if(empty($order)) {
            $message = "No order found for payment status: " . print_r($paymentStatus, true);
            \Logger::error($message);
            throw new \Exception($message);
        }

        $orderAgent = $orderManager->createOrderAgent( $order );
        $orderAgent->setPaymentProvider( $paymentProvider );

        $order = $orderAgent->updatePayment( $paymentStatus )->getOrder();

        if (in_array($paymentStatus->getStatus(), [\OnlineShop\Framework\Model\AbstractOrder::ORDER_STATE_COMMITTED, \OnlineShop\Framework\Model\AbstractOrder::ORDER_STATE_PAYMENT_AUTHORIZED])) {
            //only when payment state is committed or authorized -> proceed and commit order
            $order = $this->commitOrder( $order );
        } else {
            $order->setOrderState(null);
            $order->save();
        }

        return $order;

    }

    /**
     * @param \OnlineShop\Framework\Model\AbstractOrder $order
     *
     * @return \OnlineShop\Framework\Model\AbstractOrder
     * @throws \Exception
     */
    public function commitOrder(\OnlineShop\Framework\Model\AbstractOrder $order) {
        $this->processOrder($order);
        $order->setOrderState(\OnlineShop\Framework\Model\AbstractOrder::ORDER_STATE_COMMITTED);
        $order->save();

        try {
            $this->sendConfirmationMail($order);
        } catch(\Exception $e) {
            \Logger::err("Error during sending confirmation e-mail: " . $e);
        }
        return $order;
    }

    protected function sendConfirmationMail(\OnlineShop\Framework\Model\AbstractOrder $order) {
        $params = array();
        $params["order"] = $order;
        $params["customer"] = $order->getCustomer();
        $params["ordernumber"] = $order->getOrdernumber();

        $mail = new \Pimcore\Mail(array("document" => $this->confirmationMail, "params" => $params));
        if($order->getCustomer()) {
            $mail->addTo($order->getCustomer()->getEmail());
            $mail->send();
        } else {
            \Logger::err("No Customer found!");
        }
    }

    /**
     * implementation-specific processing of order, must be implemented in subclass (e.g. sending order to ERP-system)
     *
     * @param \OnlineShop\Framework\Model\AbstractOrder $order
     */
    protected function processOrder(\OnlineShop\Framework\Model\AbstractOrder $order) {
        //nothing to do
    }

    /**
     * @throws \Exception
     */
    public function cleanUpPendingOrders() {
        $config = Factory::getInstance()->getConfig();
        $orderManager = Factory::getInstance()->getOrderManager();

        $timestamp = \Zend_Date::now()->sub(1, \Zend_Date::HOUR)->get();

        //Abort orders with payment pending
        $list = $orderManager->buildOrderList();
        $list->addFieldCollection("PaymentInfo");
        $list->setCondition("orderState = ? AND orderdate < ?", array(\OnlineShop\Framework\Model\AbstractOrder::ORDER_STATE_PAYMENT_PENDING, $timestamp));

        foreach($list as $order) {
            \Logger::warn("Setting order " . $order->getId() . " to " . \OnlineShop\Framework\Model\AbstractOrder::ORDER_STATE_ABORTED);
            $order->setOrderState(\OnlineShop\Framework\Model\AbstractOrder::ORDER_STATE_ABORTED);
            $order->save();
        }

        //Abort payments with payment pending
        $list = $orderManager->buildOrderList();
        $list->addFieldCollection("PaymentInfo", "paymentinfo");
        $list->setCondition("`PaymentInfo~paymentinfo`.paymentState = ? AND `PaymentInfo~paymentinfo`.paymentStart < ?", array(\OnlineShop\Framework\Model\AbstractOrder::ORDER_STATE_PAYMENT_PENDING, $timestamp));
        foreach($list as $order) {
            $payments = $order->getPaymentInfo();
            foreach($payments as $payment) {
                if($payment->getPaymentState() == \OnlineShop\Framework\Model\AbstractOrder::ORDER_STATE_PAYMENT_PENDING && $payment->getPaymentStart()->getTimestamp() < $timestamp) {
                    \Logger::warn("Setting order " . $order->getId() . " payment " . $payment->getInternalPaymentId() . " to " . \OnlineShop\Framework\Model\AbstractOrder::ORDER_STATE_ABORTED);
                    $payment->setPaymentState(\OnlineShop\Framework\Model\AbstractOrder::ORDER_STATE_ABORTED);
                }
            }
            $order->save();
        }

    }
}
