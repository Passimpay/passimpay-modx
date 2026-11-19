<?php
#place to /assets/components/minishop2/payment/
define('MODX_API_MODE', true);
require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/index.php';
$modx->getService('error','error.modError');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('FILE');
$miniShop2 = $modx->getService('minishop2');
$miniShop2->loadCustomClasses('payment');
if (!class_exists('msPassimpay')) {http_response_code(503);exit('Payment handler unavailable');}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {http_response_code(405);exit('Method not allowed');}
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {http_response_code(415);exit('JSON required');}
$rawBody = file_get_contents('php://input', false, null, 0, 65537);
if ($rawBody === false || strlen($rawBody) > 65536) {http_response_code(413);exit('Invalid body');}
$data = json_decode($rawBody, true);
if (!is_array($data) || !isset($data['type'], $data['platformId'], $data['paymentId'], $data['orderId'], $data['amount']) ||
    $data['type'] !== 'deposit' || !is_int($data['platformId']) || $data['platformId'] <= 0 ||
    !is_int($data['paymentId']) || $data['paymentId'] <= 0 || !is_string($data['orderId']) ||
    !preg_match('/^[1-9][0-9]{0,9}$/D', $data['orderId']) || (float) $data['orderId'] > 4294967295 ||
    !msPaspy::validDecimal($data['amount']) || msPaspy::compareAmounts($data['amount'], '0') <= 0 ||
    (isset($data['txhash']) && (!is_string($data['txhash']) || strlen($data['txhash']) > 255)) ||
    (isset($data['addressTo']) && (!is_string($data['addressTo']) || strlen($data['addressTo']) > 256)) ||
    (isset($data['destinationTag']) && (!is_int($data['destinationTag']) || $data['destinationTag'] < 0 || $data['destinationTag'] > 4294967295))) {
    http_response_code(400);
    exit('Invalid notification');
}
$msPaspy = new msPaspy();
$config = $msPaspy->init($modx);
if (!filter_var($config['platform_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) || $config['secret_key'] === '' || $config['order_status'] <= 0) {
    http_response_code(503);exit('Payment is not configured');
}
$hash = isset($_SERVER['HTTP_X_SIGNATURE']) ? $_SERVER['HTTP_X_SIGNATURE'] : '';
$payload = json_encode($data);
if ($data['platformId'] !== (int) $config['platform_id'] || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash) ||
    !hash_equals(hash_hmac('sha256', (int) $config['platform_id'] . ';' . $payload . ';' . $config['secret_key'], $config['secret_key']), $hash)) {
    $modx->log(modX::LOG_LEVEL_ERROR, '[miniShop2:Passimpay] Hash broken for order '.$data['orderId']);
    http_response_code(403);
    exit('Invalid signature');
}

// Одна блокировка защищает свойства и статус от одновременных уведомлений.
if (!$modx->connect(null, [xPDO::OPT_CONN_MUTABLE => true])) {http_response_code(503);exit('Database unavailable');}
$pdo = $modx->pdo;
$lock = 'pp_modx_' . substr(hash('sha256', $modx->getOption('dbname') . ';' . $modx->getTableName('msOrder') . ';' . $data['orderId']), 0, 48);
$locked = false;
$process = function () use ($modx, $miniShop2, $msPaspy, $config, $data, $pdo, $lock, &$locked) {
    try {
        $query = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        if (!$query || !$query->execute([$lock]) || (int) $query->fetchColumn() !== 1) return [503, 'Payment busy'];
        $locked = true;
        $order = $modx->getObject('msOrder', ['id' => $data['orderId']], false);
        $payment = $order ? $order->getOne('Payment') : null;
        if (!$order || !$payment || $payment->get('class') !== 'msPassimpay') return [404, 'Order not found'];
        $props = $order->get('properties');
        if (!is_array($props) || empty($props['passimpay']) || !is_array($props['passimpay'])) return [409, 'Payment details missing'];
        $saved = $props['passimpay'];
        if (!isset($saved['platform_id'], $saved['order_id'], $saved['mode'], $saved['amount']) ||
            (int) $saved['platform_id'] !== $data['platformId'] || (string) $saved['order_id'] !== $data['orderId']) return [409, 'Payment details mismatch'];
        if ((int) $order->get('status') === $config['order_status']) return [200, 'OK'];
        if (!$modx->getObject('msOrderStatus', ['id' => $config['order_status'], 'active' => 1])) return [503, 'Payment status is not configured'];
        if ((int) $saved['mode'] === 2) {
            // Счёт создан в USD; криптосумма webhook не является его оплаченной суммой.
            $status = $msPaspy->getOrderStatus($data['orderId']);
            if ($status === false) return [503, 'Payment status unavailable'];
            if ($status['status'] !== 'paid') return [200, 'OK'];
            if (!msPaspy::validDecimal($saved['amount']) || msPaspy::compareAmounts($status['amountPaid'], $saved['amount']) < 0) return [503, 'Invoice amount mismatch'];
        } elseif ((int) $saved['mode'] === 1) {
            if (!is_numeric($saved['amount']) || !is_finite((float) $saved['amount']) || $saved['amount'] <= 0) return [409, 'Invalid payment amount'];
            if (!isset($saved['payment_id'], $saved['address'], $data['addressTo']) || (int) $saved['payment_id'] !== $data['paymentId'] ||
                $saved['address'] !== $data['addressTo'] || (isset($saved['destinationTag']) && (!isset($data['destinationTag']) || $saved['destinationTag'] !== $data['destinationTag']))) return [409, 'Payment currency or address mismatch'];
            if ($saved['amount'] > $data['amount']) return [200, 'OK'];
        } else {
            return [409, 'Invalid payment mode'];
        }
        if (!$pdo->beginTransaction()) return [503, 'Cannot save payment'];
        if ($miniShop2->changeOrderStatus($order->get('id'), $config['order_status']) !== true) throw new RuntimeException('Payment status change failed');
        if (!$pdo->commit()) throw new RuntimeException('Payment commit failed');
        return [200, 'OK'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $modx->log(modX::LOG_LEVEL_ERROR, '[miniShop2:Passimpay] Could not process payment for order ' . $data['orderId']);
        return [503, 'Payment processing failed'];
    } finally {
        if ($locked) {
            $query = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            if ($query) $query->execute([$lock]);
        }
    }
};
$response = $process();
http_response_code($response[0]);
echo $response[1];
