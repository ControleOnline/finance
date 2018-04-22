<?php

namespace Finance\Controller;

use Core\Model\ErrorModel;
use Core\Helper\Format;
use Finance\Model\FinanceModel;
use Sales\Model\OrderModel;
use NFePHP\DA\NFe\Danfe;
use NFePHP\DA\CTe\Dacte;

class DefaultController extends \Core\Controller\DefaultController {

    public function receiveAction() {
        if (ErrorModel::getErrors()) {
            return $this->_view;
        }

        $invoice_id = $this->params()->fromQuery('id');
        $financeModel = new FinanceModel();

        $financeModel->initialize($this->serviceLocator);
        $this->_view->invoiceStatus = $financeModel->getAllInvoiceStatus();

        if ($invoice_id) {
            $orderModel = new OrderModel();
            $orderModel->initialize($this->serviceLocator);
            $financeModel->recalculateReceiveInvoiceAmount($invoice_id);
            $this->_view->invoice = $financeModel->getReceiveInvoice($invoice_id);
            $this->_view->saleOrders = $financeModel->getSalesOrdersFromSaleInvoice($invoice_id);
            $this->_view->purchasingOrders = $financeModel->getPurchasingOrdersFromSaleInvoice($invoice_id);
            $this->_view->totalPurchasingOrders = $financeModel->getTotalPurchasingOrdersFromSaleInvoice($invoice_id);
            $this->_view->order = $this->_view->saleOrders ? $this->_view->saleOrders[0] : $this->_view->invoice->getOrder()[0]->getOrder();
            $this->_view->setTemplate('finance/default/invoice-receive.phtml');
        } else {
            $this->_view->selectedStatus = $this->params()->fromQuery('invoice-status');
            $this->_view->recieveInvoice = $financeModel->getReceiveInvoices($this->params()->fromQuery());
            $this->_view->setTemplate('finance/default/receive-list.phtml');
        }
        return $this->_view;
    }

    public function addServiceInvoiceTaxAction() {
        try {
            $params = $this->params()->fromPost();
            $invoice = file_get_contents($this->params()->fromFiles('NFE')['tmp_name']);
            if ($this->params()->fromFiles('NFE')['type'] != 'text/xml') {
                ErrorModel::addError('The invoice must be sent in XML format');
            } elseif ($params && $params['invoice'] && $invoice) {
                $financeModel = new FinanceModel();
                $financeModel->initialize($this->serviceLocator);
                $financeModel->addServiceInvoiceTax($params['invoice'], $invoice);
            } else {
                ErrorModel::addError('Invalid data');
            }
        } catch (InvalidArgumentException $e) {
            ErrorModel::addError($e->getMessage());
        }
    }

    public function serviceInvoiceTaxAction() {
        $financeModel = new FinanceModel();
        $financeModel->initialize($this->serviceLocator);
        $invoiceTax = $financeModel->getServiceInvoiceTax($this->params()->fromQuery('id'));        
        try {
            if ($invoiceTax) {
                if ($invoiceTax->getServiceInvoiceTax()[0]->getInvoiceType() == 55) {                                                                            
                    $danfe = new Danfe($invoiceTax->getServiceInvoiceTax()[0]->getServiceInvoiceTax()->getInvoice(), 'P', 'A4', 'images/logo.jpg', 'I', '');                                        
                    $id = $danfe->montaDANFE();
                    $pdf = $danfe->render();
                }                
                if ($invoiceTax->getServiceInvoiceTax()[0]->getInvoiceType() == 57) {
                    $danfe = new Dacte($invoiceTax->getServiceInvoiceTax()[0]->getServiceInvoiceTax()->getInvoice(), 'P', 'A4', 'images/logo.jpg', 'I', '');
                    $id = $danfe->montaDACTE();
                    $pdf = $danfe->render();
                }
                header('Content-Type: application/pdf');
                echo $pdf;
                exit;
            } else {
                ErrorModel::addError('Access denied on the invoice tax');
            }
        } catch (InvalidArgumentException $e) {
            ErrorModel::addError($e->getMessage());
        }
    }

    public function addProofOfPaymentAction() {
        try {
            $invoice = $this->params()->fromQuery('invoice');
            $proof = file_get_contents($this->params()->fromFiles('proof-of-payment')['tmp_name']);
            if (!in_array($this->params()->fromFiles('proof-of-payment')['type'], array('image/jpg', 'image/jpeg', 'image/png'))) {
                ErrorModel::addError('The invoice must be sent in jpg, jpeg or png format');
            } elseif ($proof && $invoice) {
                $financeModel = new FinanceModel();
                $financeModel->initialize($this->serviceLocator);
                $financeModel->addProofOfPayment($invoice, $proof);
            } else {
                ErrorModel::addError('Invalid data');
            }
        } catch (InvalidArgumentException $e) {
            ErrorModel::addError($e->getMessage());
        }
    }

    public function paymentMadeAction() {
        $invoice = $this->params()->fromPost('id');
        if ($invoice) {
            $financeModel = new FinanceModel();
            $financeModel->initialize($this->serviceLocator);
            $financeModel->paymentMade($invoice);
        } else {
            ErrorModel::addError('Invalid data');
        }
    }

    public function payAction() {
        if (ErrorModel::getErrors()) {
            return $this->_view;
        }

        $invoice_id = $this->params()->fromQuery('id');
        $financeModel = new FinanceModel();

        $financeModel->initialize($this->serviceLocator);
        $this->_view->invoiceStatus = $financeModel->getAllInvoiceStatus();

        if ($invoice_id) {
            $financeModel->recalculatePayInvoiceAmount($invoice_id);
            $this->_view->invoice = $financeModel->getPayInvoice($invoice_id);
            $this->_view->saleOrders = $financeModel->getSalesOrdersFromPurchasingInvoice($invoice_id);
            $this->_view->purchasingOrders = $financeModel->getPurchasingOrdersFromPurchasingInvoice($invoice_id);
            $this->_view->totalSalesOrders = $financeModel->getTotalSalesOrdersFromPurchasingInvoice($invoice_id);
            $this->_view->order = $this->_view->purchasingOrders ? $this->_view->purchasingOrders[0] : $this->_view->invoice->getOrder()[0]->getOrder();
            $this->_view->setTemplate('finance/default/invoice-pay.phtml');
        } else {
            $this->_view->selectedStatus = $this->params()->fromQuery('invoice-status');
            $this->_view->payInvoice = $financeModel->getPayInvoices($this->params()->fromQuery());
            $this->_view->setTemplate('finance/default/pay-list.phtml');
        }
        return $this->_view;
    }

}
