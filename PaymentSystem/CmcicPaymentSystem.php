<?php

namespace Kitano\Bundle\PaymentCmcicBundle\PaymentSystem;

use Kitano\Bundle\PaymentBundle\PaymentSystem\CreditCardInterface;
use Kitano\Bundle\PaymentBundle\Model\Transaction;
use Kitano\Bundle\PaymentBundle\Model\CreditCard\AuthorizationTransaction;
use Kitano\Bundle\PaymentBundle\Model\CreditCard\CaptureTransaction;
use Kitano\Bundle\PaymentBundle\KitanoPaymentEvents;
use Kitano\Bundle\PaymentBundle\Event\PaymentNotificationEvent;
use Kitano\Bundle\PaymentBundle\Event\PaymentCaptureEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Templating\EngineInterface;
use Kitano\Bundle\PaymentBundle\Repository\TransactionRepositoryInterface;

class CmcicPaymentSystem implements CreditCardInterface
{
    /* @var string */
    protected $lang;

    /* @var string */
    protected $tpe;

    /* @var string */
    protected $key;

    /* @var string */
    protected $version;

    /* @var string */
    protected $email;

    /* @var string */
    protected $companyCode;

    /* @var boolean */
    protected $sandbox = false;

    /* @var array */
    protected $urls = array();

    /* @var string */
    private $certificatePath;

    /* @var EventDispatcherInterface */
    protected $dispatcher;

    /* @var EngineInterface */
    protected $templating;

    /* @var TransactionRepositoryInterface */
    protected $transactionRepository;


    public function __construct(TransactionRepositoryInterface $transactionRepository, EventDispatcherInterface $dispatcher, EngineInterface $templating)
    {
        $this->transactionRepository = $transactionRepository;
        $this->dispatcher = $dispatcher;
        $this->templating = $templating;
    }

    public function authorizeAndCapture(Transaction $transaction)
    {
        // Nothing to do
    }

    /**
     * {@inheritDoc}
     */
    public function renderLinkToPayment(Transaction $transaction)
    {
        return $this->templating->render('KitanoPaymentCmcicBundle:PaymentSystem:link-to-payment.html.twig', array(
            'version' => $this->getVersion(),
            'tpe' => $this->tpe,
            'mac' => $this->computeMac($transaction),
            'lgue' => $this->getLang(),
            'societe' => $this->companyCode,
            'texte_libre' => '',
            'mail' => $this->getEmail(),
            'date' => $this->formatDate($transaction->getDate()),
            'reference' => $transaction->getOrderId(),
            'montant' => $this->formatAmount($transaction->getAmount(), $transaction->getCurrency()),
            'requestUrl' => $this->getUrl('authorize'),
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function handlePaymentNotification(Request $request)
    {
        // TODO: Implement handlePaymentNotification() method.
        $requestData = $request->request;
        $transaction = $this->transactionRepository->findByOrderId($requestData->get('reference', null));
        if (null === $transaction) {
            // TODO: erreur
        }

        $macData = sprintf('%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*',
            $requestData->get('TPE', null),
            $requestData->get('date', null),
            $requestData->get('montant', null),
            $requestData->get('reference', null),
            $requestData->get('texte-libre', null),
            $requestData->get('version', null),
            $requestData->get('code-retour', null),
            $requestData->get('cvx', null),
            $requestData->get('vld', null),
            $requestData->get('brand', null),
            $requestData->get('status3ds', null),
            $requestData->get('numauto', null),
            $requestData->get('motifrefus', null),
            $requestData->get('originecb', null),
            $requestData->get('bincb', null),
            $requestData->get('hpancb', null),
            $requestData->get('ipclient', null),
            $requestData->get('originetr', null),
            $requestData->get('veres', null),
            $requestData->get('pares', null)
        );

        $mac = strtoupper($this->hashMac($macData));

        $acknowledgment = "version=2\ncdr=1\n";
        if ($mac === $requestData['MAC']) {
            $transaction->setValid(true);
            $acknowledgment = "version=2\ncdr=0\n";
        }
        else {
            $transaction->setValid(false);
        }

        $this->transactionRepository->save($transaction);

        $event = new PaymentNotificationEvent($transaction);
        $this->dispatcher->dispatch(KitanoPaymentEvents::PAYMENT_NOTIFICATION, $event);
    }

    /**
     * {@inheritDoc}
     */
    public function capture(CaptureTransaction $transaction)
    {
        // Initialize session and set URL.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getUrl('capture'));

        // Set so curl_exec returns the result instead of outputting it.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Accept any server(peer) certificate
        //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // Verify certificate
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CAINFO, $this->getCertificatePath());

        // Data
        $data = array(
            'version'              => $this->getVersion(),
            'TPE'                  => $this->getTpe(),
            'date'                 => $this->formatDate($transaction->getDate()),
            'date_commande'        => $this->formatDate($transaction->getBaseTransaction()->getDate()),
            'montant'              => $this->formatAmount($transaction->getBaseTransaction()->getAmount(), $transaction->getCurrency()),
            'montant_a_capturer'   => $this->formatAmount($transaction->getAmount(), $transaction->getCurrency()),
            'montant_deja_capture' => $this->formatAmount(0, $transaction->getCurrency()), // TODO
            'montant_restant'      => $this->formatAmount($transaction->getRemainingAmount(), $transaction->getCurrency()),
            'reference'            => $this->formatAmount($transaction->getTransactionId(), $transaction->getCurrency()),
            'text-libre'           => '', // TODO
            'lgue'                 => $this->getLang(),
            'societe'              => $this->getCompanyCode(),
            'MAC'                  => $this->computeCaptureMac($transaction),
        );

        curl_setopt($ch, CURLOPT_POST, count($data));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->urlify($data));

        // Get the response and close the channel.
        $response = curl_exec($ch);
        curl_close($ch);

        // TODO: parse $reponse and populate Transaction
        // $paymentResponse = $this->parseCaptureResponse();

        $this->transactionRepository->save($transaction);

        // TODO: dispatch event
        $event = new PaymentCaptureEvent($transaction);
        $this->dispatcher->dispatch(KitanoPaymentEvents::PAYMENT_CAPTURE, $event);
    }

    /**
     * @return string
     */
    private function convertKey()
    {
        // hash key
        $key = $this->key;

        $hexStrKey = substr($key, 0, 38);
        $hexFinal = "" . substr($key, 38, 2) . "00";

        $cca0 = ord($hexFinal);
        if ($cca0 > 70 && $cca0 < 97) {
            $hexStrKey .= chr($cca0 - 23) . substr($hexFinal, 1, 1);
        }
        else
        {
            if (substr($hexFinal, 1, 1) == "M") {
                $hexStrKey .= substr($hexFinal, 0, 1) . "0";
            }
            else
            {
                $hexStrKey .= substr($hexFinal, 0, 2);
            }
        }

        return pack("H*", $hexStrKey);
    }

    /**
     * @param string $mac
     * @return string
     */
    private function hashMac($mac)
    {
        return strtolower(hash_hmac('sha1', $mac, $this->convertKey()));
    }

    /**
     * Computes authorize mac key
     *
     * @param  AuthorizationTransaction $transaction
     *
     * @return string
     */
    private function computeMac(Transaction $transaction)
    {
        $data = sprintf('%s*%s*%s*%s*%s*%s*%s*%s*%s**********',
            $this->tpe,
            $this->formatDate($transaction->getDate()),
            $this->formatAmount($transaction->getAmount(), $transaction->getCurrency()),
            $transaction->getTransactionId(),
            '',
            $this->getVersion(),
            $this->getLang(),
            $this->getCompanyCode(),
            $this->getEmail()
        );

        return $this->hashMac($data);
    }

    /**
     * Computes capture mac key
     *
     * @param  AuthorizationTransaction $transaction
     *
     * @return string
     */
    private function computeCaptureMac(CaptureTransaction $transaction)
    {
        $data = sprintf('%s*%s*%s%s%s*%s*%s*%s*%s*%s*',
            $this->getTpe(),
            $this->formatDate($transaction->getDate()),
            $this->formatAmount($transaction->getAmount(), $transaction->getCurrency()),
            $this->formatAmount($transaction->getCaptureAmount(), $transaction->getCurrency()),
            $this->formatAmount($transaction->getRemainingAmount(), $transaction->getCurrency()),
            $transaction->getTransactionId(),
            '',
            $this->getVersion(),
            $this->getLang(),
            $this->getCompanyCode()
        );

        return $this->hashMac($data);
    }

    /**
     * @param array $data
     * @return string
     */
    private function urlify(array $data)
    {
        return http_build_query($data);
    }

    /**
     * @param float  $amount
     * @param string $currency
     *
     * @return string
     */
    private function formatAmount($amount, $currency)
    {
        return ((string) $amount) . $currency;
    }

    /**
     * @param \DateTime $date
     * @return string
     */
    private function formatDate(\DateTime $date)
    {
        return $date->format('d/m/Y:H:i:s');
    }

    /**
     * @return string
     */
    public function getUrl($key)
    {
        if ($this->sandbox) {
            return $this->urls['sandbox'][$key];
        }

        return $this->urls['production'][$key];
    }

    /**
     * @param array $urls
     */
    public function setUrls(array $urls)
    {
        $this->urls = $urls;
    }

    /**
     * @param string $companyCode
     */
    public function setCompanyCode($companyCode)
    {
        $this->companyCode = $companyCode;
    }

    /**
     * @return string
     */
    public function getCompanyCode()
    {
        return $this->companyCode;
    }

    /**
     * @param string $key
     */
    public function setKey($key)
    {
        $this->key = $key;
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @param string $lang
     */
    public function setLang($lang)
    {
        $this->lang = $lang;
    }

    /**
     * @return string
     */
    public function getLang()
    {
        return $this->lang;
    }

    /**
     * @param string $tpe
     */
    public function setTpe($tpe)
    {
        $this->tpe = $tpe;
    }

    /**
     * @return string
     */
    public function getTpe()
    {
        return $this->tpe;
    }

    /**
     * @param string $version
     */
    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @param string $email
     */
    public function setEmail($email)
    {
        $this->email = $email;
    }

    /**
     * @return string
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * @param string $certificatePath
     */
    public function setCertificatePath($certificatePath)
    {
        $this->certificatePath = realpath($certificatePath);
    }

    /**
     * @return string
     */
    public function getCertificatePath()
    {
        return $this->certificatePath;
    }

    /**
     * @param boolean $sandbox
     */
    public function setSandbox($sandbox)
    {
        $this->sandbox = (bool) $sandbox;
    }

    /**
     * @return boolean
     */
    public function getSandbox()
    {
        return $this->sandbox;
    }
}