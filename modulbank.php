<?php

if (!defined('_VALID_MOS') && !defined('_JEXEC')) {
	die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');
}

if (!class_exists('vmPSPlugin')) {
	require JPATH_VM_PLUGINS . DS . 'vmpsplugin.php';
}

if (!class_exists("ModulbankHelper")) {
	include_once __DIR__ . DS . 'modulbanklib/ModulbankHelper.php';
}
if (!class_exists("ModulbankReceipt")) {
	include_once __DIR__ . DS . 'modulbanklib/ModulbankReceipt.php';
}

class plgVmPaymentModulbank extends vmPSPlugin
{

	// instance of class
	public static $_this = false;

	public function __construct(&$subject, $config)
	{
		if (JRequest::getVar('download_modulbank_logs', 0) == 1) {
			$user = JFactory::getUser();
			if ($user->authorise('core.manage', 'com_virtuemart')) {
				ModulbankHelper::sendPackedLogs(JFactory::getConfig()->get('log_path'));
			}
			jexit();
		}
		parent::__construct($subject, $config);
		JLog::addLogger(
			array(
				'logger'   => 'callback',
				'callback' => array($this, 'callbackLog'),
			),
			JLog::ALL,
			array('plg_vmpayment_modulbank')
		);
		$this->method      = null;
		$this->tableFields = array_keys($this->getTableSQLFields());
		if (version_compare(JVM_VERSION, '3', 'ge')) {
			$varsToPush = $this->getVarsToPush();
		} else {
			$varsToPush = array(
				'payment_logos'             => array('', 'char'),
				'countries'                 => array(0, 'int'),
				'categories'                => array(0, 'int'),
				'payment_order_total'       => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
				'payment_currency'          => array(0, 'int'),
				'min_amount'                => array(0, 'int'),
				'max_amount'                => array(0, 'int'),
				'cost_per_transaction'      => array(0, 'int'),
				'cost_percent_total'        => array(0, 'int'),
				'tax_id'                    => array(0, 'int'),
				'merchantId'                => array('', 'string'),
				'secretKey'                 => array('', 'string'),
				'secretKeyTest'             => array('', 'string'),
				'mode'                      => array('', 'string'),
				'preauth'                   => array('', 'int'),
				'successUrl'                => array('', 'string'),
				'failUrl'                   => array('', 'string'),
				'cancelUrl'                 => array('', 'string'),
				'orderStatus'               => array('U', 'string'),
				'statusSuccess'             => array('U', 'string'),
				'statusForPayment'          => array('C', 'string'),
				'orderStatusRefund'         => array('P', 'string'),
				'statusForCapture'          => array('C', 'string'),
				'paymentMessage'            => array('', 'string'),
				'taxSystem'                 => array('usn', 'string'),
				'tax'                       => array('none', 'string'),
				'taxDelivery'               => array('none', 'string'),
				'paymentObjectType'         => array('', 'string'),
				'deliveryPaymentObjectType' => array('', 'string'),
				'paymentMethodType'         => array('', 'string'),
				'logging'                   => array('', 'string'),
				'logSize'                   => array('', 'string'),
			);
		}
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);

	}

	protected function getVmPluginCreateTableSQL()
	{
		return $this->createTableSQL('Payment Ynadex Table');
	}

	public function plgVmDeclarePluginParamsPaymentVM3(&$data)
	{
		return $this->declarePluginParams('payment', $data);
	}

	public function getTableSQLFields()
	{
		$SQLfields = array(
			'id'                          => 'int(11) unsigned NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'         => 'int(11) UNSIGNED',
			'order_number'                => 'char(32)',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
			'payment_name'                => 'varchar(5000) NOT NULL DEFAULT \'\'',
			'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency'            => 'char(3)',
			'cost_per_transaction'        => ' decimal(10,2)',
			'cost_percent_total'          => ' decimal(10,2)',
			'tax_id'                      => 'smallint(11)',
			'transaction_id'              => ' varchar(32)',
		);

		return $SQLfields;
	}

	public function plgVmConfirmedOrder($cart, $order)
	{

		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$this->method = $method;
		$lang         = JFactory::getLanguage();
		$filename     = 'com_virtuemart';
		$lang->load($filename, JPATH_ADMINISTRATOR);
		$vendorId = 0;

		$session        = JFactory::getSession();
		$return_context = $session->getId();
		$this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');

		if (!class_exists('VirtueMartModelOrders')) {
			require JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php';
		}

		if (!$method->payment_currency) {
			$this->getPaymentCurrency($method);
		}

		// END printing out HTML Form code (Payment Extra Info)
		$q  = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3        = $db->loadResult();
		$paymentCurrency        = CurrencyDisplay::getInstance($method->payment_currency);
		$totalInPaymentCurrency = $paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false);
		if (method_exists($paymentCurrency, "roundForDisplay")) {
			$totalInPaymentCurrency = $paymentCurrency->roundForDisplay($totalInPaymentCurrency, $method->payment_currency);
		} else {
			$totalInPaymentCurrency = round($totalInPaymentCurrency, 2);
		}
		$cd                                      = CurrencyDisplay::getInstance($cart->pricesCurrency);
		$virtuemart_order_id                     = VirtueMartModelOrders::getOrderIdByOrderNumber($order['details']['BT']->order_number);
		$this->_virtuemart_paymentmethod_id      = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['payment_name']                = $this->renderPluginName($method);
		$dbValues['order_number']                = $order['details']['BT']->order_number;
		$dbValues['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
		$dbValues['cost_per_transaction']        = $method->cost_per_transaction;
		$dbValues['cost_percent_total']          = $method->cost_percent_total;
		$dbValues['payment_currency']            = $currency_code_3;
		$dbValues['payment_order_total']         = $totalInPaymentCurrency;
		$dbValues['tax_id']                      = $method->tax_id;
		$this->storePSPluginInternalData($dbValues);

		$redirect    = true;
		$printButton = $method->statusForPayment == $method->orderStatus;

		$html = $this->printButton($totalInPaymentCurrency, $order, $redirect, $printButton);

		$modelOrder                 = VmModel::getModel('orders');
		$order['order_status']      = $method->orderStatus;
		$order['customer_notified'] = 1;
		$order['comments']          = '';
		$modelOrder->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, true);

		//$cart->emptyCart();
		JRequest::setVar('html', $html);
		$cart->orderdoneHtml = $html;
		return true;
	}

	public function printButton($totalInPaymentCurrency, $order, $redirect = 0, $printButton = 1, $escapeJson = 0)
	{
		$html        = '';
		$orderNumber = $order['details']['BT']->order_number;
		$sysinfo     = [
			'language' => 'PHP ' . phpversion(),
			'plugin'   => $this->getVersion(),
			'cms'      => $this->getCmsVersion(),
		];
		if ($printButton) {
			$linkAppend = '&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&Itemid=' . vRequest::getInt('Itemid');
			$data       = [
				'merchant'        => $this->method->merchantId,
				'amount'          => $totalInPaymentCurrency,
				'order_id'        => $orderNumber,
				'testing'         => $this->method->mode == 'test' ? 1 : 0,
				'preauth'         => $this->method->preauth,
				'description'     => 'Оплата заказа №' . $orderNumber,
				'success_url'     => $this->method->successUrl . $linkAppend,
				'fail_url'        => $this->method->failUrl . $linkAppend,
				'cancel_url'      => $this->method->cancelUrl,
				'callback_url'    => JUri::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&pelement=modulbank',
				'client_name'     => $order['details']['BT']->first_name . ' ' . $order['details']['BT']->last_name,
				'client_email'    => $order['details']['BT']->email,
				'receipt_contact' => $order['details']['BT']->email,
				'receipt_items'   => $this->getReceipt($order),
				'unix_timestamp'  => time(),
				'sysinfo'         => json_encode($sysinfo),
				'salt'            => ModulbankHelper::getSalt(),
			];
			$key = $this->method->mode == 'test' ? $this->method->secretKeyTest : $this->method->secretKey;

			$data['signature'] = ModulbankHelper::calcSignature($key, $data);

			$this->log($order, 'orderData');
			$this->log($data, 'paymentForm');

			$link = "https://pay.modulbank.ru/pay";
			$html .= '<form method="post" action="' . $link . '" name="vm_modulbank_form">';
			foreach ($data as $key => $value) {
				$html .= "<input type='hidden' name='$key' value='" . htmlspecialchars($value) . "'>";
			}
			if ($redirect == 0) {
				$html .= "<input type='submit' name='pay' value='Перейти к оплате' class='vm_modulbank_button btn'>";
			}
			$html .= '</form>';
			if ($redirect == 1) {
				$html .= 'Сейчас вы будете перемещены на страницу оплаты';
				$html .= ' <script type="text/javascript">';
				$html .= ' setTimeout(function(){document.forms.vm_modulbank_form.submit();},2000);';
				$html .= ' </script>';
			}
		} else {
			if ($order['details']['BT']->order_status == 'P' || $order['details']['BT']->order_status == 'U') {
				$html .= $method->paymentMessage;
			}
		}
		return $html;
	}

	public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
	{
		if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
			return null; // Another method was selected, do nothing
		}

		$db = JFactory::getDBO();
		$q  = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
			vmWarn(500, $q . " " . $db->getErrorMsg());
			return '';
		}
		$this->getPaymentCurrency($paymentTable);

		$html = '<table class="adminlist">' . "\n";
		$html .= $this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_NAME', $paymentTable->payment_name);
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
		$html .= '</table>' . "\n";
		return $html;
	}

	/**
	 * Check if the payment conditions are fulfilled for this payment method
	 * @author: Valerie Isaksen
	 *
	 * @param $cart_prices: cart prices
	 * @param $payment
	 * @return true: if the conditions are fulfilled, false otherwise
	 *
	 */
	protected function checkConditions($cart, $method, $cart_prices)
	{

// 		$params = new JParameter($payment->payment_params);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		$amount      = $cart_prices['salesPrice'];
		$amount_cond = ($amount >= $method->min_amount and $amount <= $method->max_amount
			or
			($method->min_amount <= $amount and ($method->max_amount == 0)));
		if (!$amount_cond) {
			return false;
		}
		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}

		// probably did not gave his BT:ST address
		if (!is_array($address)) {
			$address                          = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}

		if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
			return true;
		}

		return false;
	}

	/*
	 * We must reimplement this triggers for joomla 1.7
	 */

	/**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 * @author Valérie Isaksen
	 *
	 */
	public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
	{
		return $this->onStoreInstallPluginTable($jplugin_id);
	}

	/**
	 * This event is fired after the payment method has been selected. It can be used to store
	 * additional payment info in the cart.
	 *
	 * @author Max Milbers
	 * @author Valérie isaksen
	 *
	 * @param VirtueMartCart $cart: the actual cart
	 * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
	 *
	 */
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
	{
		return $this->OnSelectCheck($cart);
	}

	/**
	 * plgVmDisplayListFEPayment
	 * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
	 *
	 * @param object $cart Cart object
	 * @param integer $selected ID of the method selected
	 * @return boolean True on succes, false on failures, null when this plugin was not selected.
	 * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
	 *
	 * @author Valerie Isaksen
	 * @author Max Milbers
	 */
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
	{
		return $this->displayListFE($cart, $selected, $htmlIn);
	}

	/*
	 * plgVmonSelectedCalculatePricePayment
	 * Calculate the price (value, tax_id) of the selected method
	 * It is called by the calculator
	 * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
	 * @author Valerie Isaksen
	 * @cart: VirtueMartCart the current cart
	 * @cart_prices: array the new cart prices
	 * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
	 *
	 *
	 */

	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
	{
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}

	public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
	{

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$this->getPaymentCurrency($method);

		$paymentCurrencyId = $method->payment_currency;
	}

	/**
	 * plgVmOnCheckAutomaticSelectedPayment
	 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	 * The plugin must check first if it is the correct type
	 * @author Valerie Isaksen
	 * @param VirtueMartCart cart: the cart object
	 * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
	 *
	 */
	public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
	{
		return $this->onCheckAutomaticSelected($cart, $cart_prices);
	}

	/**
	 * This method is fired when showing the order details in the frontend.
	 * It displays the method-specific data.
	 *
	 * @param integer $order_id The order ID
	 * @return mixed Null for methods that aren't active, text (HTML) otherwise
	 * @author Max Milbers
	 * @author Valerie Isaksen
	 */
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
	{
		if (!($this->method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($this->method->payment_element)) {
			return false;
		}
		$result = $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
		if (JRequest::getVar('option') == 'com_virtuemart' &&
			JRequest::getVar('view') == 'orders' &&
			JRequest::getVar('layout') == 'details') {
			if (!class_exists('CurrencyDisplay')) {
				require JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php';
			}

			if (!class_exists('VirtueMartModelOrders')) {
				require JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php';
			}

			$orderModel = VmModel::getModel('orders');
			$order      = $orderModel->getOrder($virtuemart_order_id);
			$this->getPaymentCurrency($method);
			$paymentCurrency        = CurrencyDisplay::getInstance($this->method->payment_currency);
			$totalInPaymentCurrency = $paymentCurrency->convertCurrencyTo($this->method->payment_currency, $order['details']['BT']->order_total, false);
			if (method_exists($paymentCurrency, "roundForDisplay")) {
				$totalInPaymentCurrency = $paymentCurrency->roundForDisplay($totalInPaymentCurrency, $this->method->payment_currency);
			} else {
				$totalInPaymentCurrency = round($totalInPaymentCurrency, 2);
			}

			$redirect    = JRequest::getInt('redirect', 0);
			$printButton = $order['details']['BT']->order_status == $this->method->statusForPayment;

			$output = $this->printButton($totalInPaymentCurrency, $order, $redirect, $printButton);
			$payment_name .= $output;
		}
		return $result;
	}

	/**
	 * This event is fired during the checkout process. It can be used to validate the
	 * method data as entered by the user.
	 *
	 * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
	 * @author Max Milbers

	public function plgVmOnCheckoutCheckDataPayment(  VirtueMartCart $cart) {
	return null;
	}
	 */

	/**
	 * This method is fired when showing when priting an Order
	 * It displays the the payment method-specific data.
	 *
	 * @param integer $_virtuemart_order_id The order ID
	 * @param integer $method_id  method used for this order
	 * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
	 * @author Valerie Isaksen
	 */
	public function plgVmonShowOrderPrintPayment($order_number, $method_id)
	{
		return $this->onShowOrderPrint($order_number, $method_id);
	}

	public function plgVmDeclarePluginParamsPayment($name, $id, &$data)
	{
		return $this->declarePluginParams('payment', $name, $id, $data);
	}

	public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
	{
		return $this->setOnTablePluginParams($name, $id, $table);
	}

	public function plgVmOnUpdateOrderPayment($data, $old_status)
	{
		if (!($this->method = $this->getVmPluginMethod($data->virtuemart_paymentmethod_id))) {
			return null;
		}
		if (!$this->selectedThisElement($this->method->payment_element)) {
			return null;
		}
		if (!class_exists('VirtueMartModelOrders')) {
			require JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php';
		}

		$orderModel = VmModel::getModel('orders');
		$order      = $orderModel->getOrder($data->virtuemart_order_id);
		$this->getPaymentCurrency($this->method);
		if ($data->order_status == $this->method->orderStatusRefund) {
			$db = JFactory::getDBO();
			$db->setQuery("SELECT ROUND(payment_order_total,2) as amount, transaction_id FROM #__virtuemart_payment_plg_modulbank WHERE virtuemart_order_id={$data->virtuemart_order_id} order by id desc");
			$transaction    = $db->loadObject();
			$merchant       = $this->method->merchantId;
			$amount         = $transaction->amount;
			$transaction_id = $transaction->transaction_id;
			$key            = $this->method->mode == 'test' ? $this->method->secretKeyTest : $this->method->secretKey;
			$this->log(array($merchant, $amount, $transaction_id), 'refund');
			$response = ModulbankHelper::refund($merchant, $amount, $transaction_id, $key);
			$this->log($response, 'refund_response');
			return null;
		}

		if ($data->order_status == $this->method->statusForCapture && $this->method->preauth) {
			$db = JFactory::getDBO();
			$db->setQuery("SELECT ROUND(payment_order_total,2) as amount, transaction_id FROM #__virtuemart_payment_plg_modulbank WHERE virtuemart_order_id={$data->virtuemart_order_id} order by id desc");
			$transaction = $db->loadObject();
			$receiptJson = $this->getReceipt($order);
			$data        = [
				'merchant'        => $this->method->merchantId,
				'amount'          => $transaction->amount,
				'transaction'     => $transaction->transaction_id,
				'receipt_contact' => $order['details']['BT']->email,
				'receipt_items'   => $receiptJson,
				'unix_timestamp'  => time(),
				'salt'            => ModulbankHelper::getSalt(),
			];

			$key = $this->method->mode == 'test' ? $this->method->secretKeyTest : $this->method->secretKey;
			$this->log($data, 'capture');
			$response = ModulbankHelper::capture($data, $key);
			$this->log($response, 'capture_result');
			return null;
		}

		return null;
	}

	private function getReceipt($order)
	{
		$paymentCurrency        = CurrencyDisplay::getInstance($method->payment_currency);
		$totalInPaymentCurrency = $paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false);
		if (method_exists($paymentCurrency, "roundForDisplay")) {
			$totalInPaymentCurrency = $paymentCurrency->roundForDisplay($totalInPaymentCurrency, $method->payment_currency);
		} else {
			$totalInPaymentCurrency = round($totalInPaymentCurrency, 2);
		}
		$receipt  = new ModulbankReceipt($this->method->taxSystem, $this->method->paymentMethodType, $totalInPaymentCurrency);
		$shipping = $order['details']['BT']->order_shipment + $order['details']['BT']->order_shipment_tax;
		foreach ($order['items'] as $item) {
			$receipt->addItem($item->order_item_name, $item->product_final_price, $this->method->tax, $this->method->paymentObjectType, $item->product_quantity);
		}
		if ($shipping > 0) {
			$receipt->addItem('Доставка', $shipping, $this->method->taxDelivery, $this->method->deliveryPaymentObjectType);
		}
		return $receipt->getJson();
	}

	public function plgVmOnUpdateOrderBEPayment($virtuemart_order_id)
	{
		$orderModel = VmModel::getModel('orders');
		$order      = $orderModel->getOrder($virtuemart_order_id);
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return null;
		}
		return null;

	}

	public function plgVmOnPaymentNotification()
	{
		if (JRequest::getVar('pelement') != 'modulbank') {
			return null;
		}

		if (!class_exists('VirtueMartModelOrders')) {
			require JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php';
		}

		$order_number = JRequest::getVar('order_id');
		if (!$order_number) {
			return false;
		}

		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
		$payment             = $this->getDataByOrderId($virtuemart_order_id);
		$orderModel          = VmModel::getModel('orders');
		$order               = $orderModel->getOrder($virtuemart_order_id);
		$this->method        = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);
		$post                = JRequest::get('post');
		$this->log($post, 'callback');
		if ($this->checkSign()) {
			if (
				($post['state'] === 'COMPLETE' || $post['state'] === 'AUTHORIZED')
				&& $order['details']['BT']->order_status != $this->method->statusForCapture
			) {
				$order                        = array();
				$order['order_status']        = $this->method->statusSuccess;
				$order['customer_notified']   = 1;
				$order['virtuemart_order_id'] = $virtuemart_order_id;
				$order['comments']            = '';
				$modelOrder                   = new VirtueMartModelOrders();
				if (!defined('K_TCPDF_THROW_EXCEPTION_ERROR')) {
					define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
				}
				try {
					ob_start();
					$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
					ob_clean();
				} catch (Exception $e) {

				};
				echo "Success";
			}
			$db          = JFactory::getDBO();
			$paymentName = $this->renderPluginName($this->method);
			$db->setQuery("
				UPDATE #__virtuemart_payment_plg_modulbank
				set transaction_id=" . $db->quote($post['transaction_id']) . ",
				payment_name=" . $db->quote($paymentName) . ",
				payment_order_total=" . $db->quote($post['amount']) . "
				WHERE virtuemart_order_id=" . $db->quote($virtuemart_order_id));
			$db->query();
		} else {
			$this->error('Wrong signatire');
		}
		jexit();
	}

	private function error($msg)
	{
		$this->log($msg, 'error');
		throw new Exception($msg, 1);
	}

	private function checkSign()
	{
		$key       = $this->method->mode == 'test' ? $this->method->secretKeyTest : $this->method->secretKey;
		$post      = JRequest::get('post');
		unset($post['view']);
		$signature = ModulbankHelper::calcSignature($key, $post);
		return strcasecmp($signature, JRequest::getVar('signature')) == 0;
	}

	public function plgVmSetOnTablePluginPayment($data, $table)
	{
		if (!$this->selectedThisElement($data['payment_element'])) {
			return false;
		}
	}

	protected function displayLogos($logo_list)
	{

		$ds = "";
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$ds = "\\";
		} else {
			$ds = "/";
		}
		$img = "";

		if (!(empty($logo_list))) {
			$url = JURI::root() . str_replace(JPATH_ROOT . $ds, '', dirname(__FILE__)) . '/';
			if (!is_array($logo_list)) {
				$logo_list = (array) $logo_list;
			}

			foreach ($logo_list as $logo) {
				if ($logo == -1) {
					continue;
				}

				$alt_text = substr($logo, 0, strpos($logo, '.'));
				$img .= '<span class="vmCartPaymentLogo"><img align="middle" src="' . $url . $logo . '"  alt="' . $alt_text . '" ></span>';
			}
		}
		return $img;
	}

	private function getTransactionStatus($transaction)
	{
		$merchant = $this->method->merchantId;
		$this->log([$merchant, $transaction], 'getTransactionStatus');

		$key = $this->method->mode == 'test' ? $this->method->secretKeyTest : $this->method->secretKey;

		$result = ModulbankHelper::getTransactionStatus(
			$merchant,
			$transaction,
			$key
		);
		$this->log($result, 'getTransactionStatus_response');
		return json_decode($result);
	}

	public function plgVmOnPaymentResponseReceived(&$html)
	{

		if (!class_exists('VirtueMartModelOrders')) {
			require JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php';
		}

		$order_number = JRequest::getVar('on', '');
		$pass         = JRequest::getVar('pass', '');

		if (!$order_number) {
			return false;
		}

		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
		$payment             = $this->getDataByOrderId($virtuemart_order_id);
		if (!($this->method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($this->method->payment_element)) {
			return false;
		}
		$orderModel = VmModel::getModel('orders');
		$order      = $orderModel->getOrder($virtuemart_order_id);
		$this->emptyCart(null, $order_number);
		$payment_name      = $this->renderPluginName($this->method);
		$transactionResult = $this->getTransactionStatus(JRequest::getVar('transaction_id'));
		$paymentStatusText = "Ожидаем поступления средств";
		if (isset($transactionResult->status) && $transactionResult->status == "ok") {

			switch ($transactionResult->transaction->state) {
				case 'PROCESSING':$paymentStatusText = "В процессе";
					break;
				case 'WAITING_FOR_3DS':$paymentStatusText = "Ожидает 3DS";
					break;
				case 'FAILED':$paymentStatusText = "При оплате возникла ошибка";
					break;
				case 'COMPLETE':$paymentStatusText = "Оплата прошла успешно";
					break;
				case 'AUTHORIZED':$paymentStatusText = "Оплата прошла успешно";
					break;
				default:$paymentStatusText = "Ожидаем поступления средств";
			}
		}
		ob_start();
		?>
		<br />
<table>
	<tr>
    	<td>Способ оплаты</td>
        <td><?php echo $payment_name; ?></td>
    </tr>

	<tr>
    	<td>Номер заказа</td>
        <td><?php echo $order['details']['BT']->order_number; ?></td>
    </tr>
	<tr>
		<td>Статус оплаты</td>
        <td><?php echo $paymentStatusText ?></td>
    </tr>

</table>
	<br />
	<a class="vm-button-correct" href="<?php echo JRoute::_('index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $order['details']['BT']->order_number . '&order_pass=' . $order['details']['BT']->order_pass, false) ?>">Перейти на страницу заказа</a>
	<br>
		<?php
$html .= ob_get_clean();
	}

	public function plgVmOnUserPaymentCancel()
	{

		if (!class_exists('VirtueMartModelOrders')) {
			require JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php';
		}

		$order_number = JRequest::getVar('on', '');
		$pass         = JRequest::getVar('pass', '');
		if (!$order_number) {
			return false;
		}

		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
		$payment             = $this->getDataByOrderId($virtuemart_order_id);
		if (!($method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		if ($method->not_empty_cart) {
			$this->emptyCart(null, $order_number);
		}
		JFactory::getApplication()->redirect("index.php?option=com_virtuemart&view=orders&layout=details&order_number=$order_number&order_pass=$pass", 'Оплата не удалась, попробуйте ещё раз');

		//JRequest::setVar('paymentResponse', $returnValue);
		return true;
	}

	private function getVersion()
	{
		$info = json_decode($this->methods[0]->manifest_cache);
		return $info->version;
	}

	private function getCmsVersion()
	{
		$jversion = new JVersion();
		return 'Joomla ' . $jversion->getShortVersion() . ' Virtuemart ' . vmVersion::$RELEASE;
	}

	public function log($data, $category)
	{
		if ($this->method->logging) {
			$context = [
				'data' => $data,
				'size' => $this->method->logSize,
			];
			JLog::add($category, JLog::INFO, 'plg_vmpayment_modulbank', null, $context);
		}
	}

	public function callbackLog($entry)
	{
		$logName = JFactory::getConfig()->get('log_path') . '/modulbank.log';
		ModulbankHelper::log($logName, $entry->context['data'], $entry->message, $entry->context['size']);
	}
}
// No closing tag
