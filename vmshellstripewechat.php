<?php
// 文件编码声明，确保中文不乱码
header('Content-Type: text/html; charset=UTF-8');

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// 加载 Stripe PHP 库
require_once __DIR__ . '/stripe-php/init.php';

use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\PaymentIntent;
use Stripe\Refund;

function vmshellstripewechat_MetaData()
{
    return [
        'DisplayName' => 'VmShell-Stripe-WeChatPay',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function vmshellstripewechat_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'VmShell-Stripe-WeChatPAY',
        ],
        'stripeSecretKey' => [
            'FriendlyName' => 'Stripe 秘密密钥',
            'Type' => 'text',
            'Size' => '50',
            'Description' => '请输入您的 Stripe 秘密密钥 (SK_LIVE)。',
        ],
        'stripePublishableKey' => [
            'FriendlyName' => 'Stripe 发布密钥',
            'Type' => 'text',
            'Size' => '50',
            'Description' => '请输入您的 Stripe 发布密钥 (PK_LIVE)。',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook 密钥',
            'Type' => 'text',
            'Size' => '50',
            'Description' => '请输入您的 Stripe Webhook 密钥，用于验证回调签名。',
        ],
        'currency' => [
            'FriendlyName' => '收款货币',
            'Type' => 'dropdown',
            'Options' => [
                'USD' => '美元 (USD)',
                'CNY' => '人民币 (CNY)',
                'HKD' => '港币 (HKD)',
                'EUR' => '欧元 (EUR)',
                'AUD' => '澳元 (AUD)',
                'CAD' => '加元 (CAD)',
                'GBP' => '英镑 (GBP)',
                'JPY' => '日元 (JPY)',
                'SGD' => '新加坡元 (SGD)',
                'DKK' => '丹麦克朗 (DKK)',
                'NOK' => '挪威克朗 (NOK)',
                'SEK' => '瑞典克朗 (SEK)',
                'CHF' => '瑞士法郎 (CHF)',
            ],
            'Default' => 'USD', // 默认 USD
            'Description' => '选择微信支付的收款货币，支持多种货币（取决于业务所在地）。',
        ],
        'feePercentage' => [
            'FriendlyName' => '手续费百分比',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '2.9',
            'Description' => '每笔交易收取的手续费百分比（默认 2.9%）。',
        ],
        'feeFixed' => [
            'FriendlyName' => '固定手续费',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '0.3',
            'Description' => '每笔交易收取的固定手续费（默认 0.3）。',
        ],
    ];
}

function vmshellstripewechat_link($params)
{
    if (empty($params['stripeSecretKey']) || empty($params['stripePublishableKey'])) {
        logTransaction('vmshellstripewechat', ['error' => 'Stripe密钥未配置'], '配置错误');
        return '<div style="color: red; text-align: center;">错误：支付网关未正确配置，请联系管理员。</div>';
    }

    $publishableKey = htmlspecialchars($params['stripePublishableKey'], ENT_QUOTES, 'UTF-8');
    $secretKey = $params['stripeSecretKey'];
    $invoiceId = (int)$params['invoiceid'];
    $description = htmlspecialchars($params['description'], ENT_QUOTES, 'UTF-8');
    $amount = floatval($params['amount']);
    $gatewayCurrency = strtolower($params['currency']); // 默认 USD
    $totalAmountCents = (int)($amount * 100);
    $returnUrl = htmlspecialchars($params['systemurl'] . '/viewinvoice.php?id=' . $invoiceId . '&payment=success', ENT_QUOTES, 'UTF-8');

    $stripe = new StripeClient($secretKey);
    Stripe::setApiVersion('2024-06-20'); // 指定 API 版本

    try {
        // 创建 PaymentIntent
        $paymentIntent = $stripe->paymentIntents->create([
            'amount' => $totalAmountCents,
            'currency' => $gatewayCurrency,
            'payment_method_types' => ['wechat_pay'],
            'payment_method_options' => [
                'wechat_pay' => [
                    'client' => 'web',
                ],
            ],
            'description' => $description,
            'metadata' => [
                'invoice_id' => $invoiceId,
                'original_amount' => $amount,
            ],
        ]);

        // 记录创建状态
        logTransaction('vmshellstripewechat', [
            'payment_intent_id' => $paymentIntent->id,
            'status' => $paymentIntent->status,
            'next_action' => $paymentIntent->next_action,
        ], 'PaymentIntent 创建');

        // 确认 PaymentIntent
        $paymentIntent = $stripe->paymentIntents->confirm(
            $paymentIntent->id,
            [
                'payment_method_data' => [
                    'type' => 'wechat_pay',
                ],
                'return_url' => $returnUrl,
            ]
        );

        // 记录确认状态
        logTransaction('vmshellstripewechat', [
            'payment_intent_id' => $paymentIntent->id,
            'status' => $paymentIntent->status,
            'next_action' => $paymentIntent->next_action,
        ], 'PaymentIntent 确认');

        // 检查是否生成二维码
        if ($paymentIntent->status !== 'requires_action' || !$paymentIntent->next_action || $paymentIntent->next_action->type !== 'wechat_pay_display_qr_code') {
            throw new Exception('未生成微信支付二维码，当前状态：' . $paymentIntent->status);
        }

        // 使用 image_url_png 作为二维码 URL
        $qrCodeUrl = $paymentIntent->next_action->wechat_pay_display_qr_code->image_url_png;
        $clientSecret = $paymentIntent->client_secret;

        // 构造 HTML 和 JavaScript
        $htmlOutput = '<script src="https://js.stripe.com/v3/"></script>';
        $htmlOutput .= '<style>
            #wechat-qr-container { max-width: 300px; margin: 20px auto; text-align: center; }
            #wechat-qr { width: 200px; height: 200px; margin-bottom: 10px; }
            #payment-status { font-size: 14px; color: #666; margin-top: 10px; }
        </style>';
        $htmlOutput .= '<div id="wechat-qr-container">';
        $htmlOutput .= '<img src="' . htmlspecialchars($qrCodeUrl, ENT_QUOTES, 'UTF-8') . '" id="wechat-qr" alt="微信支付二维码">';
        $htmlOutput .= '<div id="payment-status">请使用微信扫描二维码支付 ' . number_format($amount, 2) . ' ' . strtoupper($gatewayCurrency) . '</div>';
        $htmlOutput .= '</div>';

        $htmlOutput .= "
        <script>
            (function() {
                const stripe = Stripe('$publishableKey', { apiVersion: '2024-06-20' });
                const clientSecret = '$clientSecret';
                const statusDiv = document.getElementById('payment-status');

                console.log('二维码 URL:', '" . htmlspecialchars($qrCodeUrl, ENT_QUOTES, 'UTF-8') . "');
                console.log('Client Secret:', clientSecret);

                function checkPaymentStatus() {
                    stripe.retrievePaymentIntent(clientSecret).then(function(response) {
                        console.log('支付状态:', response);
                        if (response.error) {
                            statusDiv.textContent = '检查支付状态失败：' + response.error.message;
                        } else if (response.paymentIntent.status === 'succeeded') {
                            statusDiv.textContent = '支付成功！正在跳转...';
                            setTimeout(() => { window.location.href = '$returnUrl'; }, 2000);
                        } else {
                            setTimeout(checkPaymentStatus, 2000); // 每2秒检查
                        }
                    });
                }
                checkPaymentStatus();
            })();
        </script>";

        return $htmlOutput;
    } catch (\Exception $e) {
        logTransaction('vmshellstripewechat', ['error' => $e->getMessage()], '支付处理失败');
        return '<div style="color: red; text-align: center;">错误：支付处理失败 - ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

function vmshellstripewechat_refund($params)
{
    $secretKey = $params['stripeSecretKey'];
    $transactionId = htmlspecialchars($params['transid'], ENT_QUOTES, 'UTF-8');
    $amount = floatval($params['amount']);
    $feePercentage = floatval($params['feePercentage']) / 100; // 转换为小数
    $feeFixed = floatval($params['feeFixed']);

    if (empty($transactionId) || $amount <= 0) {
        logTransaction('vmshellstripewechat', ['error' => '无效的退款参数'], '退款失败');
        return ['status' => 'error', 'message' => '退款失败：无效参数'];
    }

    // 计算手续费并确定实际退款金额
    $feeAmount = ($amount * $feePercentage) + $feeFixed;
    $refundableAmount = max(0, $amount - $feeAmount); // 确保不会退负数
    $refundAmountCents = (int)($refundableAmount * 100);

    $stripe = new StripeClient($secretKey);
    Stripe::setApiVersion('2024-06-20');

    try {
        $refund = $stripe->refunds->create([
            'payment_intent' => $transactionId,
            'amount' => $refundAmountCents,
            'metadata' => [
                'invoice_id' => $params['invoiceid'],
                'original_amount' => $amount,
                'fee_percentage' => $params['feePercentage'],
                'fee_fixed' => $feeFixed,
                'fee_amount' => $feeAmount,
                'refundable_amount' => $refundableAmount,
            ],
        ]);

        logTransaction('vmshellstripewechat', [
            'refund_id' => $refund->id,
            'original_amount' => $amount,
            'fee_amount' => $feeAmount,
            'refunded_amount' => $refundableAmount,
        ], '退款成功');

        return [
            'status' => ($refund->status === 'succeeded' || $refund->status === 'pending') ? 'success' : 'error',
            'transid' => $refund->id,
            'amount' => $refundableAmount,
            'rawdata' => (array)$refund,
            'message' => "退款已处理，原金额: $amount，手续费: $feeAmount，实际退款: $refundableAmount，预计24小时内到账。\nThe refund has been processed, original amount: $amount, fee: $feeAmount, refunded: $refundableAmount, expected within 24 hours.",
        ];
    } catch (\Exception $e) {
        logTransaction('vmshellstripewechat', ['error' => $e->getMessage()], '退款失败');
        return [
            'status' => 'error',
            'rawdata' => $e->getMessage(),
            'transid' => $transactionId,
            'message' => '退款失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
        ];
    }
}