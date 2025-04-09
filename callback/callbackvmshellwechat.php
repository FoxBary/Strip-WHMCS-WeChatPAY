<?php
// 文件编码声明，确保中文不乱码
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// 加载 Stripe PHP 库
require_once __DIR__ . '/../stripe-php/init.php';

use Stripe\Stripe;
use Stripe\Webhook;

$gatewayModule = 'vmshellstripewechat';
$GATEWAY = getGatewayVariables($gatewayModule);

if (!$GATEWAY['type']) {
    die('模块未激活');
}

$stripeSecretKey = $GATEWAY['stripeSecretKey'];
$webhookSecret = $GATEWAY['webhookSecret'];
$feePercentage = floatval($GATEWAY['feePercentage']) / 100; // 转换为小数
$feeFixed = floatval($GATEWAY['feeFixed']);

if (empty($stripeSecretKey) || empty($webhookSecret)) {
    logTransaction($gatewayModule, ['error' => 'Stripe密钥或Webhook密钥未配置'], '配置错误');
    http_response_code(500);
    exit();
}

Stripe::setApiKey($stripeSecretKey);
Stripe::setApiVersion('2024-06-20');

$input = @file_get_contents("php://input");
if ($input === false) {
    logTransaction($gatewayModule, ['error' => '无法读取Webhook输入'], 'Webhook失败');
    http_response_code(400);
    exit();
}

$event = null;

try {
    $event = Webhook::constructEvent($input, $_SERVER['HTTP_STRIPE_SIGNATURE'], $webhookSecret);
} catch (\Exception $e) {
    logTransaction($gatewayModule, ['error' => $e->getMessage()], 'Webhook 签名验证失败');
    http_response_code(400);
    exit();
}

if ($event->type === 'payment_intent.succeeded') {
    $paymentIntent = $event->data->object;
    $invoiceId = (int)$paymentIntent->metadata->invoice_id;

    if ($invoiceId && $paymentIntent->amount > 0) {
        $amountCents = (int)$paymentIntent->amount;
        $grossAmount = round($amountCents / 100, 2);
        $feeAmount = ($grossAmount * $feePercentage) + $feeFixed;
        $netAmount = $grossAmount - $feeAmount;
        $transactionId = htmlspecialchars($paymentIntent->id, ENT_QUOTES, 'UTF-8');

        checkCbTransID($transactionId);
        addInvoicePayment(
            $invoiceId,
            $transactionId,
            $grossAmount, // 记录总金额
            $feeAmount,   // 记录手续费
            $gatewayModule
        );
        logTransaction($gatewayModule, [
            'invoice_id' => $invoiceId,
            'transaction_id' => $transactionId,
            'gross_amount' => $grossAmount,
            'fee_amount' => $feeAmount,
            'net_amount' => $netAmount,
        ], '支付成功');
    } else {
        logTransaction($gatewayModule, ['error' => '无效的支付数据'], '支付失败');
    }
} elseif ($event->type === 'payment_intent.payment_failed') {
    logTransaction($gatewayModule, (array)$event->data->object, '支付失败');
}

http_response_code(200);