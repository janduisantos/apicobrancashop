<?php



 class Conn{



   private $host;

   private $user;

   private $senha;

   private $bd;





  public function pdo(){



    $host   = "localhost";

    $user   = "u695985407_cobranca";

    $senha  = "sW7c^4rK9O[";

    $bd     = "u695985407_cobranca";

    try{

      $pdo = new PDO("mysql:host=$host;dbname=$bd", $user, $senha, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES UTF8MB4"));

      return $pdo;

    }catch(PDOException $e){

      return false;

    }

  }



 }



 ?>