<?php

    

    $curl = curl_init();

    

    curl_setopt_array($curl, array(

      CURLOPT_URL => 'https://cobranca.shop/checkout/backend/api.php',

      CURLOPT_RETURNTRANSFER => true,

      CURLOPT_ENCODING => '',

      CURLOPT_MAXREDIRS => 10,

      CURLOPT_TIMEOUT => 0,

      CURLOPT_FOLLOWLOCATION => true,

      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,

      CURLOPT_CUSTOMREQUEST => 'PUT',

      CURLOPT_POSTFIELDS =>'{

        "payment_method": "pix",

        "invoice_id": "'.$invoiceData->ref.'"

    }',

      CURLOPT_HTTPHEADER => array(

        'Content-Type: application/json',

        'Authorization: Bearer 832YBVE78204POXSV24-34987OPEVX83920-X$SD09878X-22WS-23894765XCZXWQ435HTER564',

        'Cookie: Cookie_2=value; PHPSESSID=4b6332ebd1614656f869baba3c3c56e9'

      ),

    ));

    

    $response = curl_exec($curl);

    $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

   

    if($httpcode == 200){

        

        $dados_payment = json_decode($response);

        if($dados_payment->erro == false){

            $dados_template->$keyTempalte->content = "*Este é o PIX copia e cola* ❖\n\n".$dados_payment->data->pixcode;

        }

        

    }