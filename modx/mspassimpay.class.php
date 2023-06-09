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
		$this->order = &$object;
		$this->modx = $object->xpdo;
        $this->ms2  = $object->xpdo->getService('miniShop2');
        if('notset' == $this->config['platform_id'] || empty($this->config['platform_id'])) $this->makeSettings();
    }

    public function send(msOrder $order)
    {
		$msPaspy= new msPaspy();
		$config = $msPaspy->init( $this->modx );
		$ttlusd = $order->cost / floatval( $config['rateusd'] );
		if( 1==$config['mode'] )
		{
			$payment_id = (int) $_POST['passimpay_id'];
			$list   = $msPaspy->getCurList();
			foreach($list['list'] as $c) {
				if ($payment_id == $c['id']) { $cost = $ttlusd / $c['rate_usd']; break; }
			}
			$data = [
				'payment_id' => $payment_id,
				'platform_id' => $config['platform_id'],
				'order_id' => $order->id,
			];
			$payload = http_build_query($data);
			$data['hash'] = hash_hmac( 'sha256', $payload, $config['secret_key'] );
			$post_data = http_build_query($data);
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
			curl_setopt($curl, CURLOPT_URL, 'https://passimpay.io/api/getpaymentwallet');
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
			$result = curl_exec($curl);
			curl_close( $curl );
			$result = json_decode($result, true);
			$data['amount'] 	= $cost;
			$data['amount_usd'] = $ttlusd;
			$data['address']    = $result['address'];
			$data['paysys'] 	= $c;
			$data['msorder']    = $order->get('id');
			$data['mode']       = 1;
			@mail( 'webmaster@studiotata.com', 'passimpay.io/api/getpaymentwallet', print_r($result,1).print_r($data,1) );
			if($result['result']) {
				$prop = $order->get('properties');
				$prop = (array) json_encode($prop,1);
				$prop['passimpay'] = $data;
				$order->set( 'properties', json_encode($prop) );
				$order->save();
				return $this->ms2->success('Your address for a payment: ' . $data['address'], $data, [ 'info'=>'dscsdcsdcs2' ]);
			} else {
				return $this->ms2->error( 'Error in payment process! Try again later.' );
			}
		} elseif( 2==$config['mode'] ) {
			# https://passimpay.io/developers/createorder
			$data = [
				'platform_id' => $config['platform_id'],
				'order_id' => $order->id,
				'amount' => number_format($ttlusd, 2, '.', ''),
			];
			$payload = http_build_query($data);
			$data['hash'] = hash_hmac('sha256', $payload, $config['secret_key']);
			$post_data = http_build_query($data);
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
			curl_setopt($curl, CURLOPT_URL, 'https://passimpay.io/api/createorder');
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
			$result = curl_exec($curl);
			curl_close( $curl );
			$result = json_decode($result, true);
			@mail('webmaster@studiotata.com', 'passimpay.io/api/createorder', print_r($result,1).print_r($data,1) );
			if (isset($result['result']) && $result['result'] == 1)
			{
				$data['amount_usd'] = $ttlusd;
				$data['url']        = $result['url'];
				$data['msorder']    = $order->get('id');
				$data['mode']        = 2;
				$prop = $order->get('properties');
				$prop = (array) json_encode($prop,1);
				$prop['passimpay'] = $data;
				$order->set( 'properties', json_encode($prop) );
				$order->save();
				return $this->ms2->success('URL for a payment: ' . $data['url'], $data, [ 'info'=>'dscsdcsdcs2' ]);
			} else {
				return $this->ms2->error( 'Error in payment process: ' . $result['message'] );
			}
		}
		
    }

    public function receive(msOrder $order, $params = array())
    {
		// see callback script in /assets/
		@mail( 'webmaster@studiotata.com', 'ms2.receive', print_r($order,1).print_r($params,1) );
    }

    function makeSettings()
	{
		$response = $this->modx->runProcessor('system/settings/create', [ 'key'=>'mspa_secret_key',  'xtype'=>'text-password', 'area'=>$this->namespace, 'namespace'=>'minishop2', 'name'=>'Secret Key', 'description'=>'From https://passimpay.io/account/platform' ]);
		$response = $this->modx->runProcessor('system/settings/create', [ 'key'=>'mspa_platform_id', 'xtype'=>'textfield', 'area'=>$this->namespace, 'namespace'=>'minishop2', 'name'=>'Platform ID', 'description'=>'From https://passimpay.io/account/platform' ]);
		$response = $this->modx->runProcessor('system/settings/create', [ 'key'=>'mspa_rateusd',     'xtype'=>'textfield', 'area'=>$this->namespace, 'namespace'=>'minishop2', 'name'=>'USD rate', 'description'=>'Cost of 1 USD in your currency' ]);
		$response = $this->modx->runProcessor('system/settings/create', [ 'key'=>'mspa_order_status','xtype'=>'textfield', 'area'=>$this->namespace, 'namespace'=>'minishop2', 'name'=>'Order status Id', 'description'=>'Status after order will be fully payed' ]);
		$response = $this->modx->runProcessor('system/settings/create', [ 'key'=>'mspa_mode',        'xtype'=>'numberfield', 'area'=>$this->namespace, 'namespace'=>'minishop2', 'name'=>'Mode', 'description'=>'1 - obtain address for a payment, 2 - redirect to order page', 'value'=>'1' ]);

	}

	function getPaymentLink()
	{
		$prop = $this->order->get('properties');
		$data = &$prop['passimpay'];
		if( 1 == $data['mode'] ) {
			$thank_you_msg = '<div class="passimpay_wrap"><img src="' . 'https://chart.googleapis.com/chart?cht=qr&chld=H|1&chs=120&chl='.urlencode($data['address']). '" height="120" style="float:left; margin-right:30px">';
			$thank_you_msg.= '<p>Adress for a payment: <strong>' . $data['address'] . '</strong><br>Payment system: '.$data['paysys']['name'].'<br>
				Total: '.$data['amount'].' '.$data['paysys']['currency'];
			if($data['paysys']['platform']) $thank_you_msg.= ' / '. $data['paysys']['platform'];
			$thank_you_msg.= '</p></div><div style="clear:both;height:10px"></div>';
		} elseif( 2 == $data['mode'] ) {
			$thank_you_msg = '<div class="passimpay_wrap"><a href="' .$data['url']. '">Make payment</a></div>';
		}
		return $thank_you_msg;
	}

}

class msPaspy
{

	public function init($modx)
	{
		$this->modx = &$modx;
        $this->config = [
            'platform_id'  => trim($this->modx->getOption('mspa_platform_id', null, 'notset', true)),
            'secret_key'   => trim($this->modx->getOption('mspa_secret_key', null, '', true)),
            'rateusd'      => floatval( $this->modx->getOption('mspa_rateusd', null, '1', true) ),
            'order_status' => intval( $this->modx->getOption('mspa_order_status', null, '1', true) ),
            'mode'         => intval( $this->modx->getOption('mspa_mode', null, '1', true) ),
        ];
		return $this->config;
	}

	public function payment_fields() 
	{
		$miniShop2 = $this->modx->getService('miniShop2');
		if( 1== $this->mode ) {
			$order = $miniShop2->order->get();
			$cost  = $miniShop2->order->getCost();
			$ttlusd = $cost['data']['cost'] / $this->config['rateusd'];
			$list = $this->getCurList();
			$html = '<div class="form-row form-row-wide">
				<label>Choose your type<span class="required">*</span></label>
					<select name="passimpay_id" onchange="jQuery(document.body).trigger(\'update_checkout\')">';
			@session_start();
			foreach($list['list'] as $c) {
				$active = $_SESSION['passimpay_id'] == $c['id'];
				$cost = $ttlusd / $c['rate_usd'];
				$html.= '<option value="' .$c['id']. '"'.($active?'selected':'').'>' .$c['name']. ' ' . $c['platform'] . ' - ' . $cost . ' ' .$c['currency']. '</option>';
			}

			$html.=  '</select>
				</div>
				<div class="clear"></div>';
			return $html;
		}
	}

	function getCurList()
	{
		$url = 'https://passimpay.io/api/currencies';
		$payload = http_build_query(['platform_id' => $this->config['platform_id'] ]);
		$hash = hash_hmac('sha256', $payload, $this->config['secret_key'] );
		$data = [ 'platform_id' => $this->config['platform_id'], 'hash' => $hash,];
		$post_data = http_build_query($data);
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		$result = curl_exec($curl);
		curl_close( $curl );
		$result = json_decode($result, true);
		#echo '<pre>', print_r($result,1); exit;
		return $result;
	}
	
}

}
