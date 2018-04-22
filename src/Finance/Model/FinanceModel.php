<?php

namespace Finance\Model;

use Core\Model\DefaultModel;
use Company\Model\CompanyModel;
use Carrier\Model\ShippingModel;
use Core\Model\ErrorModel;
use Itaucripto\Itaucripto;
use Core\Helper\Config;
use Core\Helper\Api;

class FinanceModel extends DefaultModel {

    /**
     * @var \Company\Model\CompanyModel $entity   
     */
    protected $_companyModel;

    public function initialize(\Zend\ServiceManager\ServiceManager $serviceLocator) {
        parent::initialize($serviceLocator);
        $this->_companyModel = new CompanyModel();
        $this->_companyModel->initialize($serviceLocator);
    }

    public function getAllInvoiceStatus() {
        return $this->_em->getRepository('\Core\Entity\InvoiceStatus')->findAll();
    }

    public function addProofOfPayment($invoice_id, $proof) {
        $invoice = $this->_em->getRepository('\Core\Entity\Invoice')
                        ->createQueryBuilder('I')
                        ->select()
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OI', 'WITH', 'I.id = OI.invoice')
                        ->innerJoin('\Core\Entity\Order', 'O', 'WITH', 'O.id = OI.order')
                        ->where('I.id =:id')
                        ->andWhere('O.client =:client')
                        ->setParameters(array(
                            'id' => $invoice_id,
                            'client' => $this->_companyModel->getLoggedPeopleCompany()
                        ))->getQuery()->getResult();
        if (!$invoice) {
            ErrorModel::addError('Invoice not found');
        } else {
            $invoice[0]->setPaymentResponse($proof);
            $this->_em->persist($invoice[0]);
            $this->_em->flush($invoice[0]);
        }
    }

    public function paymentMade($invoice_id) {

        if ($this->_companyModel->getLoggedPeopleCompany() && $this->_companyModel->getDefaultCompany() && $this->_companyModel->getLoggedPeopleCompany()->getId() == $this->_companyModel->getDefaultCompany()->getId()) {
            $invoice = $this->_em->getRepository('\Core\Entity\Invoice')
                            ->createQueryBuilder('I')
                            ->select()
                            ->innerJoin('\Core\Entity\OrderInvoice', 'OI', 'WITH', 'I.id = OI.invoice')
                            ->innerJoin('\Core\Entity\Order', 'O', 'WITH', 'O.id = OI.order')
                            ->where('I.id =:id')
                            ->andWhere('O.client =:client')
                            ->setParameters(array(
                                'id' => $invoice_id,
                                'client' => $this->_companyModel->getLoggedPeopleCompany()
                            ))->getQuery()->getResult();
            if (!$invoice) {
                ErrorModel::addError('Invoice not found');
            } else {
                $shippingModel = new ShippingModel();
                $shippingModel->initialize($this->serviceLocator);
                $invoiceStatus = $shippingModel->discoveryInvoiceStatus('paid', 'closed', 1, 1);
                $invoice[0]->setStatus($invoiceStatus);
                $invoice[0]->setInvoiceType('ITAÚ');
                $this->_em->persist($invoice[0]);
                $this->_em->flush($invoice[0]);
            }
        } else {
            ErrorModel::addError('Access denied');
        }
    }

    public function getServiceInvoiceTaxFromInvoice(\Core\Entity\Invoice $invoice) {
        $invoiceTax = $this->_em->getRepository('\Core\Entity\InvoiceTax')
                        ->createQueryBuilder('IT')
                        ->select()
                        ->innerJoin('\Core\Entity\ServiceInvoiceTax', 'SIT', 'WITH', 'SIT.service_invoice_tax = IT.id')
                        ->innerJoin('\Core\Entity\Invoice', 'I', 'WITH', 'I.id = SIT.invoice')
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OI', 'WITH', 'I.id = OI.invoice')
                        ->innerJoin('\Core\Entity\Order', 'O', 'WITH', 'O.id = OI.order')
                        ->where('I.id =:id')
                        ->andWhere('O.provider=:provider')
                        ->setParameters(array(
                            'id' => $invoice->getId(),
                            'provider' => $this->_companyModel->getLoggedPeopleCompany()
                        ))->getQuery()->getResult();
        return count($invoiceTax) > 0 ? $invoiceTax[0] : NULL;
    }

    public function addServiceInvoiceTax($invoice_id, $invoice_tax) {

        $invoice = $this->_em->getRepository('\Core\Entity\Invoice')->createQueryBuilder('I')
                        ->select()
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OI', 'WITH', 'I.id = OI.invoice')
                        ->innerJoin('\Core\Entity\Order', 'O', 'WITH', 'O.id = OI.order')
                        ->where('I.id =:id')
                        ->andWhere('O.provider =:provider')
                        ->setParameters(array(
                            'id' => $invoice_id,
                            'provider' => $this->_companyModel->getLoggedPeopleCompany()
                        ))->getQuery()->getResult();
        if (!$invoice) {
            ErrorModel::addError('Invoice not found');
        } else {
            if ($invoice[0]->getStatus()->getStatus() == 'waiting generate invoice') {
                $clientInvoiceTax = $this->getServiceInvoiceTaxFromInvoice($invoice[0]);
                if (!$clientInvoiceTax) {
                    $clientInvoiceTax = new \Core\Entity\InvoiceTax();
                    $clientInvoiceTax->setInvoice($invoice_tax);
                    $clientInvoiceTax->setInvoiceNumber($this->discoveryInvoiceNumber($clientInvoiceTax));
                    $this->_em->persist($clientInvoiceTax);
                    $this->_em->flush($clientInvoiceTax);

                    $orderInvoiceTax = new \Core\Entity\ServiceInvoiceTax();
                    $orderInvoiceTax->setInvoiceType(55);
                    $orderInvoiceTax->setInvoice($invoice[0]);
                    $orderInvoiceTax->setIssuer($this->_companyModel->getLoggedPeopleCompany());
                    $orderInvoiceTax->setServiceInvoiceTax($clientInvoiceTax);
                    $this->_em->persist($orderInvoiceTax);
                    $this->_em->flush($orderInvoiceTax);
                } else {
                    $clientInvoiceTax->setInvoice($invoice_tax);
                    $clientInvoiceTax->setInvoiceNumber($this->discoveryInvoiceNumber($clientInvoiceTax));
                    $this->_em->persist($clientInvoiceTax);
                    $this->_em->flush($clientInvoiceTax);
                }

                $shippingModel = new ShippingModel();
                $shippingModel->initialize($this->serviceLocator);

                if ($this->verifyPayment($invoice[0])) {
                    $invoiceStatus = $shippingModel->discoveryInvoiceStatus('paid', 'closed', 1, 1);
                } else {
                    $invoiceStatus = $shippingModel->discoveryInvoiceStatus('waiting billing', 'open', 1, 0);                    
                }

                $invoice[0]->setStatus($invoiceStatus);
                $this->_em->persist($invoice[0]);
                $this->_em->flush($invoice[0]);
            } else {
                ErrorModel::addError('Invoice is not in the correct status for sending invoice tax');
            }
        }

        return $clientInvoiceTax;
    }

    public function verifyPayment(\Core\Entity\Invoice $invoice, $returnType = 0) {
        if ($invoice) {
            $codEmp = Config::getConfig('itau-shopline-company'); //Coloque o cÃ³digo da empresa em MAIÚSCULO
            $chave = Config::getConfig('itau-shopline-key'); //Coloque a chave de criptografia em MAIÚSCULO
            $cripto = new Itaucripto();
            $params = array('DC' => $cripto->geraConsulta($codEmp, $invoice->getId(), $returnType, $chave));

            $result = Api::simpleCurl(Config::getConfig('itau-shopline-status-url'), $params);
            $xml = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml && $xml->PARAMETER && $xml->PARAMETER->PARAM) {
                foreach ($xml->PARAMETER->PARAM AS $param) {
                    $return[(string) $param->attributes()->ID] = (string) $param->attributes()->VALUE;
                }
            }
            if ($return && $return['sitPag'] == '00') {
                return true;
            }
        }
    }

    public function discoveryInvoiceNumber(\Core\Entity\InvoiceTax $invoice) {
        if (!$invoice->getInvoiceNumber()) {
            $nf = simplexml_load_string($invoice->getInvoice());
            $invoice->setInvoiceNumber($nf->CTe->infCte->ide->nCT? : $nf->NFe->infNFe->ide->nNF);
            $this->_em->persist($invoice);
            $this->_em->flush($invoice);
        }
        return $invoice->getInvoiceNumber();
    }

    /**
     * @return \Core\Entity\InvoiceTax
     */
    public function getServiceInvoiceTax($invoice_number) {

        $query = $this->_em->getRepository('\Core\Entity\InvoiceTax')
                ->createQueryBuilder('IT')
                ->select()
                ->innerJoin('\Core\Entity\ServiceInvoiceTax', 'SIT', 'WITH', 'SIT.service_invoice_tax = IT.id')
                ->innerJoin('\Core\Entity\Invoice', 'I', 'WITH', 'I.id = SIT.invoice')
                ->innerJoin('\Core\Entity\OrderInvoice', 'OIT', 'WITH', 'OIT.invoice = I.id')
                ->innerJoin('\Core\Entity\Order', 'O', 'WITH', 'O.id = OIT.order')
                ->where('O.provider=:provider OR O.client=:client')
                ->andWhere('IT.invoice_number =:invoice_number')
                ->groupBy('IT.id')
                ->setParameters(array(
            'client' => $this->_companyModel->getLoggedPeopleCompany(),
            'provider' => $this->_companyModel->getLoggedPeopleCompany(),
            'invoice_number' => $invoice_number,
        ));
        $result = $query->getQuery()->getResult();

        return $result ? $result[0] : null;
    }

    public function recalculateReceiveInvoiceAmount($invoice_id) {
        $amount = $this->getTotalSalesOrdersFromInvoice($invoice_id);
        if ($amount) {
            $this->_em->createQueryBuilder()->update('\Core\Entity\Invoice', 'I')
                    ->set('I.price', $amount)
                    ->where('I.id=:id')
                    ->setParameter('id', $invoice_id)
                    ->getQuery()->execute();
        }
        return $this->_em->getRepository('\Core\Entity\Invoice')->find($invoice_id);
    }

    public function recalculatePayInvoiceAmount($invoice_id) {
        $amount = $this->getTotalPurchasingOrdersFromInvoice($invoice_id);
        if ($amount) {
            $this->_em->createQueryBuilder()->update('\Core\Entity\Invoice', 'I')
                    ->set('I.price', $amount)
                    ->where('I.id=:id')
                    ->setParameter('id', $invoice_id)
                    ->getQuery()->execute();
        }
        return $this->_em->getRepository('\Core\Entity\Invoice')->find($invoice_id);
    }

    public function getPayInvoice($invoice_id) {
        $result = $this->_em->getRepository('\Core\Entity\Invoice')
                        ->createQueryBuilder('I')
                        ->select()
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OI', 'WITH', 'I.id = OI.invoice')
                        ->innerJoin('\Core\Entity\Order', 'O', 'WITH', 'O.id = OI.order')
                        ->where('I.id =:id')
                        ->andWhere('O.client =:client')
                        ->setParameters(array(
                            'id' => $invoice_id,
                            'client' => $this->_companyModel->getLoggedPeopleCompany()
                        ))->getQuery()->getResult();
        return $result ? $result[0] : NULL;
    }

    public function getSalesOrdersFromPurchasingInvoice($invoice_id) {
        return $this->_em->getRepository('\Core\Entity\Order')
                        ->createQueryBuilder('OS')
                        ->select()
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OIT', 'WITH', 'OS.id = OIT.order')
                        ->innerJoin('\Core\Entity\InvoiceTax', 'IT', 'WITH', 'IT.id = OIT.invoice_tax')
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OITS', 'WITH', 'IT.id = OITS.invoice_tax')
                        ->innerJoin('\Core\Entity\Order', 'OP', 'WITH', 'OP.id = OITS.order')
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OIP', 'WITH', 'OP.id = OIP.order')
                        ->innerJoin('\Core\Entity\Invoice', 'IP', 'WITH', 'OIP.invoice = IP.id')
                        ->where('IP.id=:id')
                        ->andWhere('OIT.invoice_type=:invoice_type')
                        ->andWhere('OS.provider=OP.client')
                        ->andWhere('OS.client =:company OR OS.provider =:company')
                        ->setParameters(array(
                            'invoice_type' => 57,
                            'id' => $invoice_id,
                            'company' => $this->_companyModel->getLoggedPeopleCompany()
                        ))->getQuery()->getResult();
    }

    public function getSalesOrdersFromSaleInvoice($invoice_id) {
        return $this->_em->getRepository('\Core\Entity\Order')
                        ->createQueryBuilder('OS')
                        ->select()
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OIT', 'WITH', 'OS.id = OIT.order')
                        ->innerJoin('\Core\Entity\InvoiceTax', 'IT', 'WITH', 'IT.id = OIT.invoice_tax')
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OITS', 'WITH', 'IT.id = OITS.invoice_tax')
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OI', 'WITH', 'OS.id = OI.order')
                        ->innerJoin('\Core\Entity\Invoice', 'I', 'WITH', 'OI.invoice = I.id')
                        ->where('I.id=:id')
                        ->andWhere('OIT.invoice_type=:invoice_type')
                        ->andWhere('OS.client =:company OR OS.provider =:company')
                        ->setParameters(array(
                            'invoice_type' => 55,
                            'id' => $invoice_id,
                            'company' => $this->_companyModel->getLoggedPeopleCompany()
                        ))->getQuery()->getResult();
    }

    public function getSalesOrdersFromPurchasingOrder($order_id) {
        return $this->_em->getRepository('\Core\Entity\Order')
                        ->createQueryBuilder('OS')
                        ->select()
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OIT', 'WITH', 'OS.id = OIT.order')
                        ->innerJoin('\Core\Entity\InvoiceTax', 'IT', 'WITH', 'IT.id = OIT.invoice_tax')
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OITS', 'WITH', 'IT.id = OITS.invoice_tax')
                        ->innerJoin('\Core\Entity\Order', 'OP', 'WITH', 'OP.id = OITS.order')
                        ->where('OP.id=:id')
                        ->andWhere('OIT.invoice_type=:invoice_type')
                        ->andWhere('OS.provider=OP.client')
                        ->andWhere('OS.client =:company OR OS.provider =:company')
                        ->setParameters(array(
                            'invoice_type' => 57,
                            'id' => $order_id,
                            'company' => $this->_companyModel->getLoggedPeopleCompany()
                        ))->getQuery()->getResult();
    }

    public function getTotalSalesOrdersFromInvoice($invoice_id) {
        $result = $this->_em->getRepository('\Core\Entity\Order')
                        ->createQueryBuilder('OS')
                        ->select('sum(OS.price) AS price')
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OIS', 'WITH', 'OS.id = OIS.order')
                        ->innerJoin('\Core\Entity\Invoice', 'I', 'WITH', 'OIS.invoice = I.id')
                        ->where('I.id=:id')
                        ->andWhere('OS.order_status!=:order_status')
                        ->setMaxResults(1)
                        ->setParameters(array(
                            'id' => $invoice_id,
                            'order_status' => $this->_em->getRepository('\Core\Entity\InvoiceStatus')->findBy(array('real_status' => array('canceled')))
                        ))->getQuery()->getResult();


        return $result ? $result[0]['price'] : 0;
    }

    public function getTotalSalesOrdersFromPurchasingInvoice($invoice_id) {
        $result = $this->_em->getRepository('\Core\Entity\Order')
                        ->createQueryBuilder('OS')
                        ->select('sum(OS.price) AS price')
                        ->select()
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OIT', 'WITH', 'OS.id = OIT.order')
                        ->innerJoin('\Core\Entity\InvoiceTax', 'IT', 'WITH', 'IT.id = OIT.invoice_tax')
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OITS', 'WITH', 'IT.id = OITS.invoice_tax')
                        ->innerJoin('\Core\Entity\Order', 'OP', 'WITH', 'OP.id = OITS.order')
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OIP', 'WITH', 'OP.id = OIP.order')
                        ->innerJoin('\Core\Entity\Invoice', 'IP', 'WITH', 'OIP.invoice = IP.id')
                        ->where('IP.id=:id')
                        ->andWhere('OIT.invoice_type=:invoice_type')
                        ->andWhere('OS.provider=OP.client')
                        ->andWhere('OS.client =:company OR OS.provider =:company')
                        ->setParameters(array(
                            'invoice_type' => 57,
                            'id' => $invoice_id,
                            'company' => $this->_companyModel->getLoggedPeopleCompany()
                        ))->getQuery()->getResult();

        return $result ? $result[0]['price'] : 0;
    }

    public function getTotalPurchasingOrdersFromInvoice($invoice_id) {
        $result = $this->_em->getRepository('\Core\Entity\Order')
                        ->createQueryBuilder('OP')
                        ->select('sum(OP.price) AS price')
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OIS', 'WITH', 'OP.id = OIS.order')
                        ->innerJoin('\Core\Entity\Invoice', 'I', 'WITH', 'OIS.invoice = I.id')
                        ->where('I.id=:id')
                        ->andWhere('OP.order_status!=:order_status')
                        ->andWhere('I.invoice_status!=:invoice_status')
                        ->setMaxResults(1)
                        ->setParameters(array(
                            'id' => $invoice_id,
                            'order_status' => $this->_em->getRepository('\Core\Entity\OrderStatus')->findBy(array('real_status' => array('canceled'))),
                            'invoice_status' => $this->_em->getRepository('\Core\Entity\InvoiceStatus')->findBy(array('real_status' => array('canceled'))),
                        ))->getQuery()->getResult();

        return $result ? $result[0]['price'] : 0;
    }

    public function getTotalPurchasingOrdersFromSaleInvoice($invoice_id) {
        $result = $this->_em->getRepository('\Core\Entity\Order')
                        ->createQueryBuilder('OP')
                        ->select('OP.price AS price')
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OIT', 'WITH', 'OP.id = OIT.order')
                        ->innerJoin('\Core\Entity\InvoiceTax', 'IT', 'WITH', 'IT.id = OIT.invoice_tax')
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OITP', 'WITH', 'IT.id = OITP.invoice_tax')
                        ->innerJoin('\Core\Entity\Order', 'OS', 'WITH', 'OS.id = OITP.order')
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OIS', 'WITH', 'OS.id = OIS.order')
                        ->innerJoin('\Core\Entity\Invoice', 'I', 'WITH', 'OIS.invoice = I.id')
                        ->where('I.id=:id')
                        ->andWhere('OITP.invoice_type=:invoice_type')
                        ->andWhere('OS.provider=OP.client')
                        ->andWhere('OS.client =:company OR OS.provider =:company')
                        ->andWhere('OP.order_status!=:order_status')
                        ->andWhere('I.invoice_status!=:invoice_status')
                        ->groupBy('OP.id,I.id')
                        ->setParameters(array(
                            'invoice_type' => 57,
                            'id' => $invoice_id,
                            'company' => $this->_companyModel->getLoggedPeopleCompany(),
                            'order_status' => $this->_em->getRepository('\Core\Entity\OrderStatus')->findBy(array('real_status' => array('canceled'))),
                            'invoice_status' => $this->_em->getRepository('\Core\Entity\InvoiceStatus')->findBy(array('real_status' => array('canceled'))),
                        ))->getQuery()->getResult();

        foreach ($result AS $r) {
            $price += $r['price'];
        }

        return $price;
    }

    public function getPurchasingOrdersFromPurchasingInvoice($invoice_id) {
        return $this->_em->getRepository('\Core\Entity\Order')
                        ->createQueryBuilder('OP')
                        ->select()
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OIT', 'WITH', 'OP.id = OIT.order')
                        ->innerJoin('\Core\Entity\InvoiceTax', 'IT', 'WITH', 'IT.id = OIT.invoice_tax')
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OITP', 'WITH', 'IT.id = OITP.invoice_tax')
                        ->innerJoin('\Core\Entity\Order', 'OS', 'WITH', 'OS.id = OITP.order')
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OIS', 'WITH', 'OS.id = OIS.order')
                        ->innerJoin('\Core\Entity\Invoice', 'I', 'WITH', 'OIS.invoice = I.id')
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OIP', 'WITH', 'OP.id = OIP.order')
                        ->innerJoin('\Core\Entity\Invoice', 'IP', 'WITH', 'OIP.invoice = IP.id')
                        ->where('IP.id=:id')
                        ->andWhere('OITP.invoice_type=:invoice_type')
                        ->andWhere('OS.provider=OP.client')
                        ->andWhere('OS.client =:company OR OS.provider =:company')
                        ->setParameters(array(
                            'invoice_type' => 57,
                            'id' => $invoice_id,
                            'company' => $this->_companyModel->getLoggedPeopleCompany()
                        ))->getQuery()->getResult();
    }

    public function getPurchasingOrdersFromSaleInvoice($invoice_id) {
        return $this->_em->getRepository('\Core\Entity\Order')
                        ->createQueryBuilder('OP')
                        ->select()
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OIT', 'WITH', 'OP.id = OIT.order')
                        ->innerJoin('\Core\Entity\InvoiceTax', 'IT', 'WITH', 'IT.id = OIT.invoice_tax')
                        ->innerJoin('\Core\Entity\OrderInvoiceTax', 'OITP', 'WITH', 'IT.id = OITP.invoice_tax')
                        ->innerJoin('\Core\Entity\Order', 'OS', 'WITH', 'OS.id = OITP.order')
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OIS', 'WITH', 'OS.id = OIS.order')
                        ->innerJoin('\Core\Entity\Invoice', 'I', 'WITH', 'OIS.invoice = I.id')
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OIP', 'WITH', 'OP.id = OIP.order')
                        ->innerJoin('\Core\Entity\Invoice', 'IP', 'WITH', 'OIP.invoice = IP.id')
                        ->where('I.id=:id')
                        ->andWhere('OITP.invoice_type=:invoice_type')
                        ->andWhere('OS.provider=OP.client')
                        ->andWhere('OS.client =:company OR OS.provider =:company')
                        ->setParameters(array(
                            'invoice_type' => 57,
                            'id' => $invoice_id,
                            'company' => $this->_companyModel->getLoggedPeopleCompany()
                        ))->getQuery()->getResult();
    }

    public function getReceiveInvoice($invoice_id) {
        $result = $this->_em->getRepository('\Core\Entity\Invoice')
                        ->createQueryBuilder('I')
                        ->select()
                        ->innerJoin('\Core\Entity\OrderInvoice', 'OI', 'WITH', 'I.id = OI.invoice')
                        ->innerJoin('\Core\Entity\Order', 'O', 'WITH', 'O.id = OI.order')
                        ->where('I.id =:id')
                        ->andWhere('O.provider =:provider')
                        ->setParameters(array(
                            'id' => $invoice_id,
                            'provider' => $this->_companyModel->getLoggedPeopleCompany()
                        ))->getQuery()->getResult();
        return $result ? $result[0] : NULL;
    }

    public function getReceiveInvoices($params, $limit = 50, $offset = 0) {
        if ($params['invoice-status']) {
            $real_invoice_status = $this->_em->getRepository('\Core\Entity\InvoiceStatus')->find($params['invoice-status']);
        } else {
            $real_invoice_status = $this->_em->getRepository('\Core\Entity\InvoiceStatus')->findBy(array('real_status' => array('open', 'pending')));
        }

        $query = $this->_em->getRepository('\Core\Entity\Invoice')
                ->createQueryBuilder('I')
                ->select()
                ->join('\Core\Entity\OrderInvoice', 'OI', 'WITH', 'OI.invoice = I.id')
                ->join('\Core\Entity\Order', 'O', 'WITH', 'OI.order = O.id')
                ->where('O.provider =:provider')
                ->andWhere('I.invoice_status IN(:real_invoice_status)')
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->orderBy('I.due_date', 'ASC')
                ->setParameters(array(
            'provider' => $this->_companyModel->getLoggedPeopleCompany(),
            'real_invoice_status' => $real_invoice_status
        ));
        return $query->getQuery()->getResult();
    }

    public function getPayInvoices($params, $limit = 50, $offset = 0) {
        if ($params['invoice-status']) {
            $real_invoice_status = $this->_em->getRepository('\Core\Entity\InvoiceStatus')->find($params['invoice-status']);
        } else {
            $real_invoice_status = $this->_em->getRepository('\Core\Entity\InvoiceStatus')->findBy(array('real_status' => array('open', 'pending')));
        }

        $query = $this->_em->getRepository('\Core\Entity\Invoice')
                ->createQueryBuilder('I')
                ->select()
                ->join('\Core\Entity\OrderInvoice', 'OI', 'WITH', 'OI.invoice = I.id')
                ->join('\Core\Entity\Order', 'O', 'WITH', 'OI.order = O.id')
                ->where('O.client =:client')
                ->andWhere('I.invoice_status IN(:real_invoice_status)')
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->orderBy('I.due_date', 'ASC')
                ->setParameters(array(
            'client' => $this->_companyModel->getLoggedPeopleCompany(),
            'real_invoice_status' => $real_invoice_status
        ));
        return $query->getQuery()->getResult();
    }

    public function cancelInvoices() {

        $shippingModel = new ShippingModel();
        $shippingModel->initialize($this->serviceLocator);

        $sub = $this->_em->getRepository('\Core\Entity\OrderInvoice')
                ->createQueryBuilder('OI')
                ->select()
                ->join('\Core\Entity\Order', 'O', 'WITH', 'OI.order = O.id')
                ->join('\Core\Entity\Invoice', 'I', 'WITH', 'OI.invoice = I.id')
                ->where('O.order_status IN (:order_status)')
                ->andWhere('I.invoice_status NOT IN (:invoice_status)')
                ->setMaxResults(1)
                ->setParameters(array(
            'order_status' => $shippingModel->discoveryOrderStatus('canceled', 'canceled', 1, 0)->getId(),
            'invoice_status' => $shippingModel->discoveryInvoiceStatus('canceled', 'canceled', 1, 0)->getId()
        ));
        $cancel = $sub->getQuery()->getResult();
        if (count($cancel) > 0) {
            return $this->_em->createQueryBuilder()->update('\Core\Entity\Invoice', 'I')
                            ->set('I.invoice_status', $shippingModel->discoveryInvoiceStatus('canceled', 'canceled', 1, 0)->getId())
                            ->where('I.id=:id')
                            ->setParameter('id', $cancel[0]->getInvoice()->getId())
                            ->getQuery()->execute();
        }
    }

}
