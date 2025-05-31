<?php
ini_set('display_startup_errors', 1);
ini_set('display_errors', 1);
error_reporting(-1);
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . "/../../../panel/class/Conn.class.php";
require_once __DIR__ . "/../../../panel/class/Charges.class.php";
require_once __DIR__ . "/../../../panel/class/Options.class.php";
require_once __DIR__ . "/../../../panel/class/Invoice.class.php";
require_once __DIR__ . "/../../../panel/class/Client.class.php";
require_once __DIR__ . "/../../../panel/helpers/helper.php";
require_once __DIR__ . "/../../../panel/class/Plans.class.php";
require_once __DIR__ . "/../../../panel/class/Client.class.php";
require_once __DIR__ . "/../../../panel/class/Callback.class.php";
require_once __DIR__ . "/../../../checkout/backend/lib/stripe/vendor/autoload.php";

if(!isset($_REQUEST['url'])) return responseJson(['message' => 'Requisição inválida.'], 400);

$url       = explode('/', $_REQUEST['url']);
$client_id = trim($url[0]);

$uniq    = (isset($_REQUEST['uniq']) ? $_REQUEST['uniq'] : false);
$plan_id = (isset($_REQUEST['plan_id']) ? $_REQUEST['plan_id'] : false);

$chargeClass  = new Charges($client_id);
$optionsClass = new Options($client_id);
$invoiceClass = new Invoice($client_id);
$planClass    = new Plans($client_id);
$clientClass  = new Client($client_id);

$client = $clientClass->getClient();
if(isset($client->due_date) && strtotime('now') > $client->due_date) return responseJson(['message' => 'O plano do painel do cliente expirou.'], 400);

$settingCharge = json_decode($optionsClass->getOption('setting_charge', true));
$settingChargeLast = json_decode($optionsClass->getOption('setting_charge_last', true));
$lastCharge = !empty($settingChargeLast) && !empty($settingChargeLast->active);
$datesLasted = $lastCharge ? array_filter([
    1 => $settingChargeLast->charge_last_1 ?? null,
    2 => $settingChargeLast->charge_last_2 ?? null,
    3 => $settingChargeLast->charge_last_3 ?? null,
    4 => $settingChargeLast->charge_last_4 ?? null
]) : [];

$dateNow = date('Y-m-d');
$daysAntesCharge = $settingCharge->days_antes_charge ?? '0';
$nextData = ($daysAntesCharge !== '0' ? date('Y-m-d', strtotime("+$daysAntesCharge days")) : $dateNow);

// Assinaturas vencidas
$assinaturas = $chargeClass->getSignaturesExpire($dateNow, $nextData, $uniq, $lastCharge, $datesLasted);

if(!$assinaturas || empty($assinaturas)) return responseJson(['message' => 'Nenhuma assinatura vencida encontrada.']);
if($settingCharge->days_charge === "false") return responseJson(['message' => 'Nenhum dia para cobrança configurado para o days_charge'], 400);

$instance = $chargeClass->getInstanceByClient();
if(!$instance) return responseJson(['message' => 'Instância do cliente não encontrada.'], 400);

$stripeOptions = json_decode($optionsClass->getOption('stripe', true));
if(isset($stripeOptions->private_key) && !empty($stripeOptions->private_key)){
    $stripe = new \Stripe\StripeClient($stripeOptions->private_key);
}

$renovadasAuto = [];
$enviadasLink = [];

foreach($assinaturas as $key => $assinatura){
    $plan = $planClass->getPlanByid($assinatura->plan_id);

    $invoiceLasted = $invoiceClass->getInvoiceOpen($assinatura->id);

    $dadosInvoice = new stdClass();
    $dadosInvoice->assinante_id = $assinatura->id;
    $dadosInvoice->id_assinante = $assinatura->id;
    $dadosInvoice->status = 'pending';
    $dadosInvoice->value = $plan->valor;
    $dadosInvoice->plan_id = $plan->id;
    $dadosInvoice->client_id = $client->id;

    if($invoiceLasted){
        $invoiceAddId = $invoiceLasted->id;
    } else {
        $invoiceAddId = $invoiceClass->addInvoice($dadosInvoice, true);
    }

    $invoiceData = $invoiceClass->getInvoiceByid($invoiceAddId);
    // Se for auto_renew, verifica a intenção na stripe.
    if($assinatura->auto_renew === '1'){
        if(!isset($stripe)){
            error_log("Não foi possível fazer a renovação automática do assinante." . print_r($assinatura, true));
        } else {

            try{
                // Buscar cartões salvos para o usuário.
                $customerCards = $stripe->customers->allPaymentMethods($assinatura->stripe_cus_id);
                if(count($customerCards->data) <= 0){
                    error_log("Não foi possivel fazer a renovação automática do assinante, o mesmo não possui nenhum cartão salvo na stripe." .print_r($assinatura, true));
                } else {
                    $paymentMethod = $customerCards->data[0];
                    if(!empty($invoiceData->stripe_intent)){
                        $intent = json_decode($invoiceData->stripe_intent);
                        $intent = $stripe->paymentIntents->confirm($intent->id, [
                            'payment_method' => $paymentMethod->id,
                        ]);
                    } else {

                        $intent = $stripe->paymentIntents->create([
                            'customer' => $assinatura->stripe_cus_id,
                            'amount' => (convertMoney(1, $plan->valor) * 100),
                            'currency' => 'brl',
                            'payment_method' => $paymentMethod->id,
                            'payment_method_types' => ['card'],
                            'receipt_email' => $assinatura->email,
                            'description' => 'Renovação ' . $plan->nome,
                            'confirm' => true,
                        ]);
                    }

                    if($intent && $intent->status === 'succeeded'){
                        $invoiceClass->updateInvoice($invoiceData->id, [
                            'status' => 'approved',
                            'stripe_intent' => json_encode($intent),
                        ]);

                        $callbackClass = new Callback(['reference' => $invoiceData->ref], 'stripe');
                        $responseUpdate = $callbackClass->insertFila();
                        if(!$responseUpdate){
                            $refund = $stripe->refunds->create([
                                'payment_intent' => $intent->id,
                            ]);
                            error_log("Pagamento estornado por um erro na atualização da assinatura: " . print_r($assinatura, true));
                            $invoiceClass->updateInvoice($invoiceData->id, ['status' => 'pending', 'stripe_intent' => json_encode($intent)]);
                        }

                        $renovadasAuto[] = $assinatura;
                        continue;
                    }
                }
            }
            catch(\Exception $e){
                error_log("Falha na renovação automática da assinatura:  " . $e->getMessage() . ' : ' . print_r($assinatura, true));
            }
        }
    }

    // caso não seja renovação atuomática, envia o link para o usuário.
    $templateMessage = $chargeClass->getTemplateById($plan->template_charge);
    if(!$templateMessage) continue;

    $dadosTemplate = json_decode($templateMessage->texto);
    foreach($dadosTemplate as $keyTemplate => $tema){
        switch($tema->type){
            case 'pix':
                require_once __DIR__ . "/pay/pix.php";
                break;
            case 'boleto':
                require_once __DIR__ . "/pay/boleto.php";
                break;
            case 'fatura':
                $dadosTemplate->$keyTemplate->content = "*Seu Link de Pagamento* \n https://cobranca.shop/" . base64_decode($invoiceData->ref);
                break;
        }
    }

    $contentTemplate = json_encode($dadosTemplate);

    $dados = new stdClass();
    $dados->assinante_id = $assinatura->id;
    $dados->client_id = $client->id;
    $dados->content = $contentTemplate;
    $dados->template_id = $templateMessage->id;
    $dados->instance_id = $instance->name;
    $dados->phone = $assinatura->ddi . $assinatura->whatsapp;

    // Conectar whatsapp em caso de queda.
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'http://49.13.207.13:8090/session/connect',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode(['token ' => trim($instance->name)]),
        CURLOPT_HTTPHEADER => array(
            'token: ' . trim($instance->name),
                'Content-Type: application/json'
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);

    $chargeClass->insertFila($dados);
    $chargeClass->insertCharge($dadosInvoice);
    $enviadasLink[] = $assinatura;
}

return responseJson([
    'message' => 'Cron de renovação de assinaturas finalizada.',
    'assinaturasRenovadas' => [
        'message' => 'Assinaturas renovadas automaticamentes através da stripe.',
        'assinaturas' => $renovadasAuto,
    ],
    'assinaturasLink' => [
        'message' => 'Link de cobrança enviado para o whatsapp dos clientes',
        'assinaturas' => $enviadasLink
    ]
]);