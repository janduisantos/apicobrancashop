<?php

require_once __DIR__ . "/../../checkout/backend/lib/stripe/vendor/autoload.php";
require_once __DIR__ . "/../../panel/class/Conn.class.php";
require_once __DIR__ . "/../../panel/class/Options.class.php";
require_once __DIR__ . "/../../panel/class/Payment.class.php";
require_once __DIR__ . "/../../panel/class/Client.class.php";

$con = new Conn();
$pdo = $con->pdo();
$clientClass = new Client();

// Buscar o primeiro admin para obter as credenciais do stripe.
$admin = $clientClass->getAdmin();

// Se não encontrou um admin!
if(!$admin){
    error_log("Nenhum admin foi encontrado para rodar a cron de assinaturas stripe.");
    return responseJson(['message' => 'Nenhum admin foi encontrado para rodar a cron de assinaturas stripe.'], 400);
}

$options = new Options($admin->id);
// Chaves do stripe.
$stripeData = $options->getOption('stripe', true);
if(empty($stripeData)){
    error_log("Nenhuma chave stripe encontrada para rodar a cron.");
    return responseJson(['message' => 'Nenhuma chave stripe encontrada para rodar a cron.'], 400);
}

$stripeData = json_decode($stripeData);

// Instanciar o stripe
$stripe = new \Stripe\StripeClient($stripeData->private_key);

// Buscar clientes vencidos.
$pastDue = $pdo->prepare("SELECT * FROM `client` WHERE due_date < :now");
$pastDue->execute([
    'now' => time(),
]);
if(!$pastDue){
    error_log("Ocorreu um erro ao tentar executar a query de busca de usuários vencidos.");
    return responseJson(['message' => 'Erro ao tentar executar a query de busca de usuários vencidos.'], 400);
}

$pastDue = $pastDue->fetchAll(PDO::FETCH_OBJ);

foreach($pastDue as $client){
    if(empty($client->stripe_cus_id)) continue;

    $clientClass = new Client($client->id);
    $paymentClass = new Payment($client->id);

    $planData = $clientClass->getPlanPlataformById($client->plan_id);

    // Buscar um último pagamento, onde foi cartão de crédito
    $stmt = $pdo->prepare('SELECT * FROM `payment` WHERE client_id=:clientId AND status=:statusPayment AND payment_method=:paymentMethod ORDER BY id ASC LIMIT 1');
    $stmt->execute([
        'clientId' => $client->id,
        'statusPayment' => 'approved',
        'paymentMethod' => 'CARD'
    ]);

    if(!$stmt){
        continue;
    }

    $paymentOld = $stmt->fetch(PDO::FETCH_OBJ);
    $paymentMethods = $stripe->customers->allPaymentMethods($client->stripe_cus_id, []);

    if(count($paymentMethods->data) > 0){
        // Tentar cobrar o cliente.
        $pendingInvoice = $pdo->prepare("SELECT * FROM `payment` WHERE client_id=:clientId AND status=:statusPayment ORDER BY id DESC LIMIT 1");
        $pendingInvoice->execute([
            'clientId' => $client->id,
            'statusPayment' => 'pending',
        ]);
        $pendingInvoice = $pendingInvoice->fetch(PDO::FETCH_OBJ);
        $createIntent = true;
        $stripe_intent = null;
        if($pendingInvoice){
            if(!empty($pendingInvoice->stripe_intent)){
                $stripe_intent = json_decode($pendingInvoice->stripe_intent);
                $stripe_intent = $stripe_intent->id;
                $createIntent = false;
            } else {
                $createIntent = true;
            }
        } else {
            $createIntent = true;
            $pendingInvoice = $clientClass->createPayment($planData->valor, 1);
            $pendingInvoice = $clientClass->getPaymentByRef($pendingInvoice);
        }

        if($pendingInvoice->attempts >= 5){
            error_log("Não foi possível cobrar a assinatura automaticamente. 5 tentativas excedidadas: $client->email");
            continue;            
        }

        $paymentClass->updatePayment($pendingInvoice->id, [
            'attempts' => $pendingInvoice->attempts + 1
        ]);

        

        try{
            if($createIntent){
                $intent = $stripe->paymentIntents->create([
                    'customer' => $client->stripe_cus_id,
                    'amount' => $options->convertMoney(1, $planData->valor) * 100,
                    'currency' => 'brl',
                    'payment_method_types' => ['card'],
                    'capture_method' => 'automatic',
                    'confirm' => true,
                    'payment_method' => $paymentMethods->data[0]->id,
                    'receipt_email' => $client->email,
                    'description' => 'Renovação ' . $planData->nome,
                ]);
    
            } else {
                $intent = $stripe->paymentIntents->confirm([
                    'payment_method' => $paymentMethods->data[0]->id,
                ]);
            }
            
            if($intent->status === 'succeeded'){
                $paymentClass->updatePayment($pendingInvoice->id, [
                    'status' => 'approved',
                    'payment_method' => 'CARD',
                    'stripe_intent' => json_encode($intent),
                ]);

                $clientClass->renewSubscription();
                continue;
            } else {
                error_log("Erro ao renovar a assinatura do usuário: " . print_r(['assinatura' => $client, 'intent' => $intent]));
                continue;
            }


        }
        catch(\Exception $e){
            error_log("Erro ao tentar renovar a assinatura do usuário: " . print_r($e));
            continue;
        }
    }
}

return responseJson(['message' => 'Cron executada com sucesso.']);