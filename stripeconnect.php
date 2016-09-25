<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2016 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

use Crowdfunding\Transaction\Transaction;
use Crowdfunding\Transaction\TransactionManager;
use Crowdfunding\Reward;
use Joomla\Utilities\ArrayHelper;

// no direct access
defined('_JEXEC') or die;

jimport('Prism.init');
jimport('Crowdfunding.init');
jimport('Crowdfundingfinance.init');
jimport('Emailtemplates.init');

JObserverMapper::addObserverClassToClass(
    'Crowdfunding\\Observer\\Transaction\\TransactionObserver',
    'Crowdfunding\\Transaction\\TransactionManager',
    array('typeAlias' => 'com_crowdfunding.payment')
);

/**
 * Crowdfunding Stripe Connect Payment Plug-in
 *
 * @package      Crowdfunding
 * @subpackage   Plug-ins
 */
class plgCrowdfundingPaymentStripeConnect extends Crowdfunding\Payment\Plugin
{
    public function __construct(&$subject, $config = array())
    {
        $this->serviceProvider = 'Stripe Connect';
        $this->serviceAlias    = 'stripeconnect';

        parent::__construct($subject, $config);
    }

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string                   $context This string gives information about that where it has been executed the trigger.
     * @param stdClass                 $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @return null|string
     */
    public function onProjectPayment($context, $item, $params)
    {
        if (strcmp('com_crowdfunding.payment', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // This is a URI path to the plugin folder

        $html   = array();
        $html[] = '<div class="well">';
        $html[] = '<h4><img src="plugins/crowdfundingpayment/stripeconnect/images/stripe_icon.png" width="32" height="32" alt="Stripe" />' . JText::_($this->textPrefix . '_TITLE') . '</h4>';

        $userId = (int)JFactory::getUser()->get('id');
        if ($userId === 0) {
            $html[] = '<div class="bg-warning p-10-5"><span class="fa fa-warning"></span> ' . JText::_($this->textPrefix . '_ERROR_NOT_REGISTERED_USER') . '</div>';
            $html[] = '</div>'; // Close the div "well".
            return implode("\n", $html);
        }

        if (!JComponentHelper::isInstalled('com_crowdfundingfinance')) {
            $html[] = '<div class="bg-warning p-10-5"><span class="fa fa-warning"></span> ' . JText::_($this->textPrefix . '_ERROR_CFFINANCE_NOT_INSTALLED') . '</div>';
            $html[] = '</div>'; // Close the div "well".
            return implode("\n", $html);
        }

        // Get keys.
        $cfFinanceParams = JComponentHelper::getParams('com_crowdfundingfinance');

        $apiKeys = Crowdfundingfinance\Stripe\Helper::getKeys($cfFinanceParams);
        if (!$apiKeys['published_key'] or !$apiKeys['secret_key']) {
            $html[] = '<div class="bg-warning p-10-5"><span class="fa fa-warning"></span> ' . JText::_($this->textPrefix . '_ERROR_CONFIGURATION') . '</div>';
            $html[] = '</div>'; // Close the div "well".
            return implode("\n", $html);
        }

        // Get image
        $dataImage = (!$this->params->get('logo')) ? '' : 'data-image="' . $this->params->get('logo') . '"';

        // Get company name.
        if (!$this->params->get('company_name')) {
            $dataName = 'data-name="' . htmlentities($this->app->get('sitename'), ENT_QUOTES, 'UTF-8') . '"';
        } else {
            $dataName = 'data-name="' . htmlentities($this->params->get('company_name'), ENT_QUOTES, 'UTF-8') . '"';
        }

        // Get project title.
        $dataDescription = JText::sprintf($this->textPrefix . '_INVESTING_IN_S', htmlentities($item->title, ENT_QUOTES, 'UTF-8'));

        // Get amount.
        $dataAmount = abs($item->amount * 100);

        $dataPanelLabel = (!$this->params->get('panel_label')) ? '' : 'data-panel-label="' . $this->params->get('panel_label') . '"';
        $dataLabel      = (!$this->params->get('label')) ? '' : 'data-label="' . $this->params->get('label') . '"';

        // Prepare optional data.
        $optionalData = array($dataLabel, $dataPanelLabel, $dataName, $dataImage);
        $optionalData = array_filter($optionalData);
        
        $html[] = '<form action="/index.php?com_crowdfunding" method="post">';
        $html[] = '<script
            src="https://checkout.stripe.com/checkout.js" class="stripe-button"
            data-key="' . $apiKeys['published_key'] . '"
            data-description="' . $dataDescription . '"
            data-amount="' . $dataAmount . '"
            data-currency="' . $item->currencyCode . '"
            data-allow-remember-me="' . $this->params->get('remember_me', 'true') . '"
            data-zip-code="' . $this->params->get('zip_code', 'false') . '"
            ' . implode("\n", $optionalData) . '
            >
          </script>';
        $html[] = '<input type="hidden" name="pid" value="' . (int)$item->id . '" />';
        $html[] = '<input type="hidden" name="task" value="payments.checkout" />';
        $html[] = '<input type="hidden" name="payment_service" value="stripe" />';
        $html[] = JHtml::_('form.token');
        $html[] = '</form>';

        if (JString::strlen($this->params->get('additional_info')) > 0) {
            $html[] = '<p>' . htmlentities($this->params->get('additional_info'), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        if ($this->params->get('sandbox_enabled', 1)) {
            $html[] = '<div class="bg-info p-10-5 mt-5"><span class="fa fa-info-circle"></span> ' . JText::_($this->textPrefix . '_WORKS_SANDBOX') . '</div>';
        }

        $html[] = '</div>';

        return implode("\n", $html);
    }

    /**
     * This method processes transaction data that comes from the payment gateway.
     *
     * @param string                   $context This string gives information about that where it has been executed the trigger.
     * @param stdClass                 $item
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     * @throws \OutOfBoundsException
     * @throws \RuntimeException
     *
     * @return null|stdClass
     */
    public function onPaymentsCheckout($context, $item, $params)
    {
        if (strcmp('com_crowdfunding.payments.checkout.stripe', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        $paymentResult                  = new stdClass;
        $paymentResult->project         = null;
        $paymentResult->reward          = null;
        $paymentResult->transaction     = null;
        $paymentResult->paymentSession  = null;
        $paymentResult->serviceProvider = $this->serviceProvider;
        $paymentResult->serviceAlias    = $this->serviceAlias;
        $paymentResult->triggerEvents   = true;

        $errorResult              = new stdClass;
        $errorResult->redirectUrl = JRoute::_(CrowdfundingHelperRoute::getBackingRoute($item->slug, $item->catslug));
        $errorResult->message     = JText::_($this->textPrefix.'_ERROR_CANNOT_PROCESS_CHECKOUT');

        // Validate request method
        $requestMethod = $this->app->input->getMethod();
        if (strcmp('POST', $requestMethod) !== 0) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_REQUEST_METHOD'), $this->errorType, JText::sprintf($this->textPrefix . '_ERROR_INVALID_TRANSACTION_REQUEST_METHOD', $requestMethod));
            return $errorResult;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_POST_RESPONSE'), $this->debugType, $_POST) : null;

        // Get token
        $token = $this->app->input->post->get('stripeToken');
        if (!$token) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TOKEN'), $this->errorType, $_POST);
            return $errorResult;
        }

        // Get payment session.
        $paymentSessionContext = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . (int)$item->id;
        $paymentSessionLocal   = $this->app->getUserState($paymentSessionContext);

        $paymentSessionRemote = $this->getPaymentSession(array(
            'session_id' => $paymentSessionLocal->session_id
        ));

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_SESSION'), $this->debugType, $paymentSessionRemote->getProperties()) : null;

        $containerHelper = new Crowdfunding\Container\Helper();

        // Get currency
        $currency   = $containerHelper->fetchCurrency($this->container, $params);

        // Validate transaction data
        $validData = $this->prepareTransactionData($item, $currency->getCode(), $paymentSessionRemote);
        if ($validData === null) {
            return $errorResult;
        }

        // Set the receiver ID.
        $project                  = $containerHelper->fetchProject($this->container, $validData['project_id']);
        $validData['receiver_id'] = $project->getUserId();

        // Get reward object.
        $reward = null;
        if ($validData['reward_id']) {
            $reward = $containerHelper->fetchReward($this->container, $validData['reward_id'], $project->getId());
        }

        // Prepare description.
        $description = JText::sprintf($this->textPrefix . '_INVESTING_IN_S', htmlentities($item->title, ENT_QUOTES, 'UTF-8'));

        // Get API keys
        $cfFinanceParams = JComponentHelper::getParams('com_crowdfundingfinance');
        $apiKeys = Crowdfundingfinance\Stripe\Helper::getKeys($cfFinanceParams);

        // Import Stripe library.
        jimport('Prism.libs.Stripe.init');

        // Set your secret key: remember to change this to your live secret key in production
        // See your keys here https://dashboard.stripe.com/account
        Stripe\Stripe::setApiKey($apiKeys['secret_key']);

        // Create the charge on Stripe's servers - this will charge the user's card
        try {
            // Create a customer
            $customer = Stripe\Customer::create(
                array(
                    'source'      => $token,
                    'description' => $description,
                    'metadata'    => array(
                        'txn_id' => $validData['txn_id']
                    )
                )
            );

            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CUSTOMER_OBJECT'), $this->debugType, var_export($customer, true)) : null;

            if (!$customer->id) {
                $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_CUSTOMER_OBJECT'), $this->errorType, JText::sprintf($this->textPrefix . '_CUSTOMER_OBJECT', var_export($customer, true)));
                return $errorResult;
            } else {
                $serviceData = new Joomla\Registry\Registry;
                $serviceData->set('customer_id', $customer->id);
                $validData['service_data'] = $serviceData;
            }

            // Save transaction data.
            // If it is not completed, return empty results.
            // If it is complete, continue with process transaction data
            $transaction = $this->storeTransaction($validData, $this->app->get('secret'));
            if ($transaction === null) {
                $this->log->add(JText::_($this->textPrefix . '_ERROR_STORING_TRANSACTION'), $this->errorType, JText::sprintf($this->textPrefix . '_TRANSACTION_DATA', var_export($validData, true)));
                return $errorResult;
            }

            // Generate object of data, based on the transaction properties.
            $paymentResult->transaction = $transaction;

            // Generate object of data based on the project properties.
            $paymentResult->project = $project;

            // Generate object of data based on the reward properties.
            if ($reward !== null and ($reward instanceof Crowdfunding\Reward)) {
                $paymentResult->reward = $reward;
            }

            // Generate data object, based on the payment session properties.
            $paymentResult->paymentSession = $paymentSessionRemote;

            // Removing intention.
            $this->removeIntention($paymentSessionRemote, $transaction);

        } catch (Stripe\Error\Card $e) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_STRIPE_ERROR'), $this->errorType, $e->getMessage());

            // Generate output data.
            $errorResult->redirectUrl = JRoute::_(CrowdfundingHelperRoute::getBackingRoute($item->slug, $item->catslug));
            $errorResult->message     = $e->getMessage();

            return $errorResult;
        } catch (Exception $e) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_SYSTEM'), $this->errorType, $e->getMessage());
            return $errorResult;
        }

        // Get next URL.
        $paymentResult->redirectUrl = JRoute::_(CrowdfundingHelperRoute::getBackingRoute($item->slug, $item->catslug, 'share'));

        return $paymentResult;
    }

    /**
     * Capture payments.
     *
     * @param string                   $context
     * @param stdClass                 $item This is transaction object.
     * @param Joomla\Registry\Registry $params
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     *
     * @return array|null
     */
    public function onPaymentsCapture($context, $item, $params)
    {
        if (!preg_match('/\.capture\.stripeconnect$/i', $context)) {
            return null;
        }

        if (!$this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // Load project object.
        $project = new Crowdfunding\Project(JFactory::getDbo());
        $project->load($item->project_id);

        $cfFinanceParams = JComponentHelper::getParams('com_crowdfundingfinance');

        // Get keys.
        $apiKeys = Crowdfundingfinance\Stripe\Helper::getKeys($cfFinanceParams);
        if (!$apiKeys['client_id']) {
            $message = array(
                'text' => JText::_($this->textPrefix . '_ERROR_CONFIGURATION'),
                'type' => 'error'
            );

            return $message;
        }

        $platformAlias = (!$apiKeys['test']) ? 'production' : 'test';

        $payout = new Crowdfundingfinance\Payout(JFactory::getDbo());
        $payout->setSecretKey($this->app->get('secret'));
        $payout->load(array('project_id' => $item->project_id));

        if (!$payout->getId()) {
            $message = array(
                'text' => JText::_($this->textPrefix . '_ERROR_NO_PAYOUT_OPTIONS'),
                'type' => 'error'
            );

            return $message;
        }

        $projectOwnerToken = Crowdfundingfinance\Stripe\Helper::getPayoutAccessToken($apiKeys, $payout, $cfFinanceParams->get('stripe_expiration_period', 7));
        if ($projectOwnerToken === null) {
            $message = array(
                'text' => JText::_($this->textPrefix . '_ERROR_NO_PAYOUT_OPTIONS'),
                'type' => 'error'
            );

            return $message;
        }

        $projectOwnerStripeData = $payout->getStripe();

        // Calculate the fee.
        $fundingType = $project->getFundingType();

        $fees = $this->getFees($fundingType);
        $fee  = $this->calculateFee($fundingType, $fees, $item->txn_amount);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_FEES'), $this->debugType, $fees) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_FEE'), $this->debugType, $fee) : null;

        $amount = round($item->txn_amount * 100, 0);

        try {
            // Create transaction object and get payment service data (customer ID).
            $transaction = new Transaction(JFactory::getDbo());
            $transaction->load($item->id);

            $serviceData = $transaction->getServiceData($this->app->get('secret'));

            if ($serviceData->get('customer_id') !== null and $serviceData->get('customer_id') !== '') {
                jimport('Prism.libs.Stripe.init');

                Stripe\Stripe::setApiKey($apiKeys['secret_key']);

                /** @var stdClass $response */
                $response = Stripe\Charge::create(
                    array(
                        'amount'      => $amount,
                        'currency'    => $item->txn_currency,
                        'customer'    => $serviceData->get('customer_id'),
                        'destination' => $projectOwnerStripeData->get('stripeconnect.' . $platformAlias . '.account_id'),
                        'description' => JText::sprintf($this->textPrefix . '_TITLE_CAPTURE_AMOUNT', $project->getTitle()),
                        'application_fee' => round($fee * 100, 0)
                    )
                );

                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CHARGE_RESPONSE'), $this->debugType, var_export($response, true)) : null;

                if ($response->id and $response->captured) {
                    $transaction->addExtraData(
                        array(
                            'id' => $response->id,
                            'object' => $response->object,
                            'balance_transaction' => $response->balance_transaction,
                            'captured' => (!$response->captured) ? 'false' : 'true',
                            'created' => $response->created,
                            'description' => $response->description,
                        )
                    );

                    $transaction->setParentId($item->txn_id);
                    $transaction->setTransactionId($response->id);
                    $transaction->setFee($fee);
                    $transaction->setStatus('completed');
                    $transaction->store();

                    // Remove the customer.
                    $customer = Stripe\Customer::retrieve($serviceData->get('customer_id'));
                    $customer->delete();

                    // Store service data.
                    $serviceData->set('customer_id', null);
                    $serviceData->set('charge.id', $response->id);
                    $serviceData->set('charge.object', $response->object);
                    $serviceData->set('charge.customer', $response->customer);
                    $serviceData->set('charge.destination', $response->destination);
                    $serviceData->set('charge.balance_transaction', $response->balance_transaction);

                    $transaction->setServiceData($serviceData);
                    $transaction->storeServiceData($this->app->get('secret'));

                } else {
                    $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_RESPONSE'), $this->errorType, var_export($response, true));
                    throw new RuntimeException(JText::_($this->textPrefix . '_ERROR_INVALID_RESPONSE'));
                }

            } else {
                $this->log->add(JText::_($this->textPrefix . '_ERROR_CUSTOMER_ID'), $this->errorType, var_export($item, true));
                throw new RuntimeException(JText::_($this->textPrefix . '_ERROR_CUSTOMER_ID'));
            }

        } catch (Exception $e) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_DOCAPTURE'), $this->errorType, $e->getMessage());

            $message = array(
                'text' => JText::sprintf($this->textPrefix . '_ERROR_CAPTURING_UNSUCCESSFULLY_S', $item->txn_id),
                'type' => 'error'
            );

            return $message;
        }

        $message = array(
            'text' => JText::sprintf($this->textPrefix . '_CAPTURED_SUCCESSFULLY_S', $item->txn_id),
            'type' => 'message'
        );

        return $message;
    }

    /**
     * Void payments.
     *
     * @param string                   $context
     * @param stdClass                 $item
     * @param Joomla\Registry\Registry $params
     *
     * @return array|null
     */
    public function onPaymentsVoid($context, $item, $params)
    {
        if (!preg_match('/\.void\.stripeconnect$/i', $context)) {
            return null;
        }

        if (!$this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        try {
            $transaction = new Transaction(JFactory::getDbo());
            $transaction->load($item->id);

            $serviceData = $transaction->getServiceData($this->app->get('secret'));

            // Remove the customer on Stripe.
            if (strlen($serviceData->get('customer_id')) > 0) {
                // Get keys.
                $cfFinanceParams = JComponentHelper::getParams('com_crowdfundingfinance');
                $apiKeys         = Crowdfundingfinance\Stripe\Helper::getKeys($cfFinanceParams);

                if (!$apiKeys['secret_key']) {
                    $this->log->add(JText::_($this->textPrefix . '_ERROR_SECRET_KEY_MISSING'), $this->errorType, var_export($item, true));
                    throw new RuntimeException(JText::_($this->textPrefix . '_ERROR_SECRET_KEY_MISSING'));
                } else {
                    jimport('Prism.libs.Stripe.init');

                    Stripe\Stripe::setApiKey($apiKeys['secret_key']);
                    $customer = Stripe\Customer::retrieve($serviceData->get('customer_id'));
                    $customer->delete();
                }

            } else {
                $this->log->add(JText::_($this->textPrefix . '_ERROR_CUSTOMER_ID'), $this->errorType, var_export($item, true));
                throw new RuntimeException(JText::_($this->textPrefix . '_ERROR_CUSTOMER_ID'));
            }

            // Reset service data.
            $transaction->setServiceData(new \Joomla\Registry\Registry());
            $transaction->storeServiceData($this->app->get('secret'));

            // Change the status.
            $transaction->setStatus('canceled');
            $transaction->updateStatus();

        } catch (Exception $e) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_DOVOID'), $this->errorType, $e->getMessage());

            $message = array(
                'text' => JText::sprintf($this->textPrefix . '_ERROR_VOID_UNSUCCESSFULLY', $item->txn_id),
                'type' => 'error'
            );

            return $message;
        }

        $message = array(
            'text' => JText::sprintf($this->textPrefix . '_VOID_SUCCESSFULLY_S', $item->txn_id),
            'type' => 'message'
        );

        return $message;
    }

    /**
     * Validate transaction data.
     *
     * @param stdClass              $item
     * @param string                $currencyCode
     * @param Crowdfunding\Payment\Session $paymentSession
     *
     * @throws \RuntimeException
     * @return array
     */
    protected function prepareTransactionData(&$item, $currencyCode, $paymentSession)
    {
        $date     = new JDate();

        // Prepare transaction data.
        $transactionData = array(
            'investor_id'      => $paymentSession->getUserId(),
            'receiver_id'      => (int)$item->user_id,
            'project_id'       => $paymentSession->getProjectId(),
            'reward_id'        => $paymentSession->isAnonymous() ? 0 : $paymentSession->getRewardId(),
            'txn_id'           => strtoupper(Prism\Utilities\StringHelper::generateRandomString(16, 'STXN')),
            'txn_amount'       => $item->amount,
            'txn_currency'     => $currencyCode,
            'txn_status'       => 'pending',
            'txn_date'         => $date->toSql(),
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias
        );

        // Check User Id, Project ID and Transaction ID.
        if (!$transactionData['project_id'] or !$transactionData['txn_id']) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'), $this->errorType, $transactionData);
            return null;
        }

        // Check if project record exists in database.
        $projectRecord = new Crowdfunding\Validator\Project\Record(JFactory::getDbo(), $transactionData['project_id']);
        if (!$projectRecord->isValid()) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_PROJECT'), $this->errorType, $transactionData);
            return null;
        }

        // Check if reward record exists in database.
        if ($transactionData['reward_id'] > 0) {
            $rewardRecord = new Crowdfunding\Validator\Reward\Record(JFactory::getDbo(), $transactionData['reward_id'], array('state' => Prism\Constants::PUBLISHED));
            if (!$rewardRecord->isValid()) {
                $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_REWARD'), $this->errorType, $transactionData);
                return null;
            }
        }
        
        return $transactionData;
    }

    /**
     * Save transaction
     *
     * @param array $transactionData
     * @param string$secret
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     *
     * @return Transaction|null
     */
    protected function storeTransaction(array $transactionData, $secret)
    {
        // Get transaction by txn ID
        $keys        = array(
            'txn_id' => ArrayHelper::getValue($transactionData, 'txn_id')
        );

        $transaction = new Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_OBJECT'), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction.
        // If the transaction status is completed, stop the process.
        if ($transaction->getId() and $transaction->isCompleted()) {
            return null;
        }

        // Encode extra data
        if (!empty($transactionData['extra_data'])) {
            $transactionData['extra_data'] = json_encode($transactionData['extra_data']);
        } else {
            $transactionData['extra_data'] = null;
        }

        // IMPORTANT: It must be before ->bind();
        $options = array(
            'old_status' => $transaction->getStatus(),
            'new_status' => $transactionData['txn_status']
        );

        // Create the new transaction record if there is not record.
        // If there is new record, store new data with new status.
        // Example: It has been 'pending' and now is 'completed'.
        // Example2: It has been 'pending' and now is 'failed'.
        $transaction->bind($transactionData);

        // Start database transaction.
        $db = JFactory::getDbo();
        $db->transactionStart();

        try {
            $transactionManager = new TransactionManager($db);
            $transactionManager->setTransaction($transaction);
            $transactionManager->process('com_crowdfunding.payment', $options);

            $transaction->storeServiceData($secret);
        } catch (Exception $e) {
            $db->transactionRollback();

            $this->log->add(JText::_($this->textPrefix . '_ERROR_TRANSACTION_PROCESS'), $this->errorType, $e->getMessage());
            return null;
        }

        // Commit database transaction.
        $db->transactionCommit();

        return $transaction;
    }
}
