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
}
