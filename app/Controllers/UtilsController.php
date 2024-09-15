<?php

namespace App\Controllers;

use App\Models\CompanyModel;

class UtilsController
{
  public static function soNumero($str)
  {
    return preg_replace("/[^0-9]/", "", $str);
  }

  public static function getCertifcado($certificado)
  {
    $folderPath = "app/storage/certificados";
    $certificadoPath = $folderPath . "/" . $certificado;
    return file_get_contents($certificadoPath);
  }

  public static function testCertificate($cnpj)
  {
    $companyModel = new CompanyModel();
    $company = $companyModel->find([
      "cnpj" => $cnpj
    ]);

    if ($company) {
      $company = new CompanyModel($company[0]['id']);

      $folderPath = "app/storage/certificados";
      $certificadoPath = $folderPath . "/" . $company->getCertificado();

      $certInfo = openssl_pkcs12_read(file_get_contents($certificadoPath), $certs, $company->getSenha());

      if ($certInfo) {
        $data = openssl_x509_parse($certs['cert']);
        $data = json_encode($data);
        $data = json_decode($data);

        list($nome, $documento) = explode(":", $data->subject->CN);

        $dt_emissao    = date('Y-m-d', $data->validTo_time_t);
        $dt_vencimento = date('Y-m-d', $data->validTo_time_t);

        $result = [
          "emissao" => $dt_emissao,
          "dt_vencimento" => $dt_vencimento,
          "nome" => $nome,
          "documento" => $documento
        ];

        http_response_code(200); // OK
        echo json_encode($result);
      } else {
        http_response_code(500); // Not Found
        while ($msg = openssl_error_string()) {
          echo $msg . "\n";
        }
      }
    }
  }

  public static function uploadXml($xml, $chave)
  {
    $folderPath = "app/storage/fiscal/xml";
    $fileName = "xml_" . $chave . ".xml";

    if (!file_exists($folderPath)) {
      mkdir($folderPath, 0777, true);
    }

    file_put_contents($folderPath . "/" . $fileName, $xml);

    return $fileName;
  }

  public static function uploadPdf($pdf, $chave)
  {
    $folderPath = "app/storage/fiscal/pdf";
    $fileName = "pdf_" . $chave . ".pdf";

    if (!file_exists($folderPath)) {
      mkdir($folderPath, 0777, true);
    }

    file_put_contents($folderPath . "/" . $fileName, $pdf);

    return $folderPath . "/" . $fileName;
  }

  function gerarCpfValido()
  {
    $n1 = rand(0, 9);
    $n2 = rand(0, 9);
    $n3 = rand(0, 9);
    $n4 = rand(0, 9);
    $n5 = rand(0, 9);
    $n6 = rand(0, 9);
    $n7 = rand(0, 9);
    $n8 = rand(0, 9);
    $n9 = rand(0, 9);

    $d1 = $n9 * 2 + $n8 * 3 + $n7 * 4 + $n6 * 5 + $n5 * 6 + $n4 * 7 + $n3 * 8 + $n2 * 9 + $n1 * 10;
    $d1 = 11 - ($this->mod($d1, 11));
    $d1 = ($d1 >= 10) ? 0 : $d1;

    $d2 = $d1 * 2 + $n9 * 3 + $n8 * 4 + $n7 * 5 + $n6 * 6 + $n5 * 7 + $n4 * 8 + $n3 * 9 + $n2 * 10 + $n1 * 11;
    $d2 = 11 - ($this->mod($d2, 11));
    $d2 = ($d2 >= 10) ? 0 : $d2;

    return "$n1$n2$n3$n4$n5$n6$n7$n8$n9$d1$d2";
  }

  private function mod($dividendo, $divisor)
  {
    return round($dividendo - (floor($dividendo / $divisor) * $divisor));
  }

  public static function verificarOperacaoPorCFOP($cfop)
  {
    $primeiroDigito = substr($cfop, 0, 1);

    if (in_array($primeiroDigito, ['1', '2', '3'])) {
      return 0;
    } elseif (in_array($primeiroDigito, ['5', '6', '7'])) {
      return 1;
    }

    return 0;
  }
}
