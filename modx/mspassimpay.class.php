<?php
if (!class_exists('msPaymentInterface')) {
	$old = MODX_CORE_PATH . 'components/minishop2/handlers/mspaymenthandler.class.php';
	if(file_exists($old)) require_once $old;
	else require_once MODX_CORE_PATH . 'components/minishop2/model/minishop2/mspaymenthandler.class.php';
}
	
#ini_set('display_errors',1);

if (!class_exists('msPassimpay')) {
class msPassimpay extends msPaymentHandler
{
    public $modx;
    public $ms2;
    public $config;
    public $namespace = 'mspassimpay';

    function __construct(xPDOObject $object, $config = array())
    {
        parent::__construct($object, $config);
        if ($this->modx->getOption('mspa_platform_id', null, 'notset', true) === 'notset') $this->makeSettings();
    }

    public function send(msOrder $order)
    {
		$msPaspy= new msPaspy();
		$config = $msPaspy->init( $this->modx );
		if (!filter_var($config['platform_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ||
			$config['secret_key'] === '' || !is_finite($config['rateusd']) || $config['rateusd'] <= 0 ||
			!in_array($config['mode'], [1, 2], true) || !is_numeric($order->cost) || !is_finite((float) $order->cost) || $order->cost <= 0) {
			return $this->ms2->error('Invalid payment settings or amount.');
		}
		$ttlusd = $order->cost / floatval( $config['rateusd'] );
		if (!is_finite($ttlusd) || $ttlusd <= 0) return $this->ms2->error('Invalid payment amount.');
		if( 1==$config['mode'] )
		{
			$payment_id = isset($_POST['passimpay_id']) && is_scalar($_POST['passimpay_id'])
				? filter_var($_POST['passimpay_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
			$cost = null;
			$list   = $msPaspy->getCurList();
			foreach($list['list'] as $c) {
				if ($payment_id == $c['id']) { $cost = $ttlusd / $c['rate_usd']; break; }
			}
			if ($cost === null || !is_finite($cost) || $cost <= 0) return $this->ms2->error('Invalid payment currency.');
			$data = [
				'payment_id' => $payment_id,
				'platform_id' => $config['platform_id'],
				'order_id' => $order->id,
			];
			$post_data = json_encode([
				'platformId' => (int) $data['platform_id'],
				'paymentId' => (int) $data['payment_id'],
				'orderId' => (string) $data['order_id'],
			]);
			$hash = hash_hmac('sha256', (int) $config['platform_id'] . ';' . $post_data . ';' . $config['secret_key'], $config['secret_key']);
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-signature: ' . $hash]);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
			curl_setopt($curl, CURLOPT_URL, 'https://api.passimpay.io/v2/address');
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
			$result = curl_exec($curl);
			$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			curl_close( $curl );
			$result = json_decode($result, true);
			if ($httpCode !== 200 || !is_array($result) || !isset($result['result'], $result['address']) || $result['result'] !== 1 ||
				!is_string($result['address']) || !preg_match('/^[a-zA-Z0-9:_-]{1,256}$/D', $result['address']) ||
				(isset($result['destinationTag']) && (!is_int($result['destinationTag']) || $result['destinationTag'] < 0 || $result['destinationTag'] > 4294967295))) {
				return $this->ms2->error('Error in payment process! Try again later.');
			}
			$data['amount'] 	= $cost;
			$data['amount_usd'] = $ttlusd;
			$data['address']    = $result['address'];
			$data['paysys'] 	= $c;
			$data['msorder']    = $order->get('id');
			$data['mode']       = 1;
			if (isset($result['destinationTag']) && is_int($result['destinationTag'])) $data['destinationTag'] = $result['destinationTag'];
			
			if($result['result']) {
				$prop = $order->get('properties');
				$prop = is_array($prop) ? $prop : [];
				$prop['passimpay'] = $data;
				$order->set( 'properties', $prop );
				if (!$order->save()) return $this->ms2->error('Cannot save payment details.');
				return $this->ms2->success('Your address for a payment: ' . $data['address'], $data, [ 'info'=>'dscsdcsdcs2' ]);
			} else {
				return $this->ms2->error( 'Error in payment process! Try again later.' );
			}
		} elseif( 2==$config['mode'] ) {
			# https://passimpay.gitbook.io/passimpay-api/create-an-invoice-link
			$data = [
				'platform_id' => $config['platform_id'],
				'order_id' => $order->id,
				'amount' => number_format($ttlusd, 2, '.', ''),
			];
			$post_data = json_encode([
				'platformId' => (int) $data['platform_id'],
				'orderId' => (string) $data['order_id'],
				'amount' => $data['amount'],
			]);
			$hash = hash_hmac('sha256', (int) $config['platform_id'] . ';' . $post_data . ';' . $config['secret_key'], $config['secret_key']);
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-signature: ' . $hash]);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
			curl_setopt($curl, CURLOPT_URL, 'https://api.passimpay.io/v2/createorder');
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
			$result = curl_exec($curl);
			$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			curl_close( $curl );
			$result = json_decode($result, true);
			
			if ($httpCode === 200 && is_array($result) && isset($result['result'], $result['url']) && $result['result'] === 1 && msPaspy::validPaymentUrl($result['url']))
			{
				$data['amount_usd'] = $ttlusd;
				$data['url']        = $result['url'];
				$data['msorder']    = $order->get('id');
				$data['mode']        = 2;
				$prop = $order->get('properties');
				$prop = is_array($prop) ? $prop : [];
				$prop['passimpay'] = $data;
				$order->set( 'properties', $prop );
				if (!$order->save()) return $this->ms2->error('Cannot save payment details.');
				return $this->ms2->success('URL for a payment: ' . $data['url'], $data, [ 'info'=>'dscsdcsdcs2' ]);
			} else {
				if (is_array($result) && isset($result['message']) && is_string($result['message'])) {
					return $this->ms2->error('Error in payment process: ' . msPaspy::escape($result['message']));
				}
				return $this->ms2->error('Error in payment process! Try again later.');
			}
		}
		
    }

    public function receive(msOrder $order, $params = array())
    {
		// Подтверждение оплаты выполняет подписанный callback в assets/components/minishop2/payment/.
    }

    function makeSettings()
	{
		$response = $this->modx->runProcessor('system/settings/create', [ 'key'=>'mspa_secret_key',  'xtype'=>'text-password', 'area'=>$this->namespace, 'namespace'=>'minishop2', 'name'=>'Secret Key', 'description'=>'From https://account.passimpay.io/' ]);
		$response = $this->modx->runProcessor('system/settings/create', [ 'key'=>'mspa_platform_id', 'xtype'=>'textfield', 'area'=>$this->namespace, 'namespace'=>'minishop2', 'name'=>'Platform ID', 'description'=>'From https://account.passimpay.io/' ]);
		$response = $this->modx->runProcessor('system/settings/create', [ 'key'=>'mspa_rateusd',     'xtype'=>'textfield', 'area'=>$this->namespace, 'namespace'=>'minishop2', 'name'=>'USD rate', 'description'=>'Cost of 1 USD in your currency' ]);
		$response = $this->modx->runProcessor('system/settings/create', [ 'key'=>'mspa_order_status','xtype'=>'textfield', 'area'=>$this->namespace, 'namespace'=>'minishop2', 'name'=>'Order status Id', 'description'=>'Status after order will be fully payed' ]);
		$response = $this->modx->runProcessor('system/settings/create', [ 'key'=>'mspa_mode',        'xtype'=>'numberfield', 'area'=>$this->namespace, 'namespace'=>'minishop2', 'name'=>'Mode', 'description'=>'1 - obtain address for a payment, 2 - redirect to order page', 'value'=>'1' ]);

	}

	function getPaymentLink(msOrder $order)
	{
		$prop = $order->get('properties');
		if (!is_array($prop) || empty($prop['passimpay']) || !is_array($prop['passimpay'])) return '';
		$data = $prop['passimpay'];
		if (isset($data['mode']) && 1 == $data['mode'] && isset($data['address'], $data['amount'], $data['paysys']) && is_array($data['paysys']) && isset($data['paysys']['name'], $data['paysys']['currency'])) {
			$thank_you_msg = '<div class="passimpay_wrap"><img src="https://payment.passimpay.io/qr-code/default/' . rawurlencode((string) $data['address']) . '" height="120" style="float:left; margin-right:30px">';
			$thank_you_msg .= '<p>Adress for a payment: <strong>' . msPaspy::escape($data['address']) . '</strong><br>Payment system: ' . msPaspy::escape($data['paysys']['name']) . '<br>';
			$thank_you_msg .= 'Total: ' . msPaspy::escape($data['amount']) . ' ' . msPaspy::escape($data['paysys']['currency']);
			if (!empty($data['paysys']['platform'])) $thank_you_msg .= ' / ' . msPaspy::escape($data['paysys']['platform']);
			if (isset($data['destinationTag'])) $thank_you_msg .= '<br>Destination Tag: ' . msPaspy::escape($data['destinationTag']);
			$thank_you_msg .= '</p></div><div style="clear:both;height:10px"></div>';
			return $thank_you_msg;
		} elseif (isset($data['mode'], $data['url']) && 2 == $data['mode'] && msPaspy::validPaymentUrl($data['url'])) {
			return '<div class="passimpay_wrap"><a href="' . msPaspy::escape($data['url']) . '">Make payment</a></div>';
		}
		return '';
	}

}

class msPaspy
{
	public $modx;
	public $config;

	public function init($modx)
	{
		$this->modx = &$modx;
        $this->config = [
            'platform_id'  => trim($this->modx->getOption('mspa_platform_id', null, 'notset', true)),
            'secret_key'   => trim($this->modx->getOption('mspa_secret_key', null, '', true)),
            'rateusd'      => is_numeric($this->modx->getOption('mspa_rateusd', null, '1', true)) ? floatval($this->modx->getOption('mspa_rateusd', null, '1', true)) : 0,
            'order_status' => (int) filter_var($this->modx->getOption('mspa_order_status', null, '1', true), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
            'mode'         => (int) filter_var($this->modx->getOption('mspa_mode', null, '1', true), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2]]),
        ];
		return $this->config;
	}

	public function payment_fields() 
	{
		$miniShop2 = $this->modx->getService('miniShop2');
		if (1 == $this->config['mode'] && is_finite($this->config['rateusd']) && $this->config['rateusd'] > 0) {
			$order = $miniShop2->order->get();
			$cost  = $miniShop2->order->getCost();
			$ttlusd = $cost['data']['cost'] / $this->config['rateusd'];
			$list = $this->getCurList();
			$html = '<div class="form-row form-row-wide">
				<label>Choose your type<span class="required">*</span></label>
					<select name="passimpay_id" onchange="jQuery(document.body).trigger(\'update_checkout\')">';
			if (session_status() === PHP_SESSION_NONE) session_start();
			foreach($list['list'] as $c) {
				$active = isset($_SESSION['passimpay_id']) && $_SESSION['passimpay_id'] == $c['id'];
				$cost = $ttlusd / $c['rate_usd'];
				$html.= '<option value="' . $c['id'] . '"' . ($active ? ' selected' : '') . '>' . self::escape($c['name']) . ' ' . self::escape($c['platform']) . ' - ' . self::escape($cost) . ' ' . self::escape($c['currency']) . '</option>';
			}

			$html.=  '</select>
				</div>
				<div class="clear"></div>';
			return $html;
		}
	}

	function getCurList()
	{
		$url = 'https://api.passimpay.io/v2/currencies';
		$platformId = filter_var($this->config['platform_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		$apikey = $this->config['secret_key'];
		if ($platformId === false || !is_string($apikey) || $apikey === '') return ['result' => 0, 'list' => []];
		$post_data = json_encode(['platformId' => $platformId]);
		$hash = hash_hmac('sha256', $platformId . ';' . $post_data . ';' . $apikey, $apikey);
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-signature: ' . $hash]);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
		$result = curl_exec($curl);
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close( $curl );
		$result = json_decode($result, true);
		if ($httpCode !== 200 || !is_array($result) || !isset($result['result'], $result['list']) || $result['result'] !== 1 || !is_array($result['list'])) return ['result' => 0, 'list' => []];
		if (isset($result['list']) && is_array($result['list'])) {
			foreach ($result['list'] as &$currency) {
				if (!is_array($currency) || !isset($currency['id'], $currency['rateUsd'], $currency['name'], $currency['currency'], $currency['network']) ||
					!is_int($currency['id']) || $currency['id'] <= 0 || !self::validDecimal($currency['rateUsd']) || (float) $currency['rateUsd'] <= 0 ||
					!is_string($currency['name']) || strlen($currency['name']) > 255 || !is_string($currency['currency']) || strlen($currency['currency']) > 32 ||
					!is_string($currency['network']) || strlen($currency['network']) > 255) return ['result' => 0, 'list' => []];
				$currency['rate_usd'] = $currency['rateUsd'];
				$currency['platform'] = $currency['network'];
				unset($currency['rateUsd'], $currency['network']);
			}
			unset($currency);
		}
		return $result;
	}
	
	public function getOrderStatus($orderId)
	{
		$post_data = json_encode(['platformId' => (int) $this->config['platform_id'], 'orderId' => (string) $orderId]);
		$apikey = $this->config['secret_key'];
		$hash = hash_hmac('sha256', (int) $this->config['platform_id'] . ';' . $post_data . ';' . $apikey, $apikey);
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-signature: ' . $hash]);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($curl, CURLOPT_URL, 'https://api.passimpay.io/v2/orderstatus');
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
		$result = curl_exec($curl);
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		$result = json_decode($result, true);
		if ($httpCode !== 200 || !is_array($result) || !isset($result['result'], $result['status'], $result['amountPaid'], $result['currency']) ||
			$result['result'] !== 1 || !in_array($result['status'], ['paid', 'wait', 'error'], true) ||
			!self::validDecimal($result['amountPaid']) || $result['currency'] !== 'USD') return false;
		return $result;
	}

	public static function compareAmounts($left, $right)
	{
		// Сравниваем десятичные строки без потери точности на малых криптосуммах.
		$a = explode('.', $left, 2);
		$b = explode('.', $right, 2);
		$a[0] = ltrim($a[0], '0');
		$b[0] = ltrim($b[0], '0');
		if (strlen($a[0]) !== strlen($b[0])) return strlen($a[0]) < strlen($b[0]) ? -1 : 1;
		$whole = strcmp($a[0], $b[0]);
		if ($whole !== 0) return $whole;
		$af = isset($a[1]) ? $a[1] : '';
		$bf = isset($b[1]) ? $b[1] : '';
		$scale = max(strlen($af), strlen($bf));
		return strcmp(str_pad($af, $scale, '0'), str_pad($bf, $scale, '0'));
	}

	public static function escape($value)
	{
		return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	public static function validPaymentUrl($url)
	{
		if (!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) return false;
		$parts = parse_url($url);
		return $parts && $parts['scheme'] === 'https' && !isset($parts['user']) && !isset($parts['pass']);
	}

	public static function validDecimal($value)
	{
		return is_string($value) && preg_match('/^[0-9]{1,30}(?:\.[0-9]{1,30})?$/D', $value);
	}

}

}
