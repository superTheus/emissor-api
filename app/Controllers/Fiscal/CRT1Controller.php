<?php

namespace App\Controllers\Fiscal;

use App\Controllers\UtilsController;
use stdClass;

/**
 * Controller para emissão de NFe para empresas do Simples Nacional (CRT 1)
 *
 * Para empresas optantes pelo regime do Simples Nacional, que unifica impostos em guia única.
 * Utiliza ICMSSN com CSOSN (Código de Situação da Operação no Simples Nacional)
 */
class CRT1Controller extends BaseFiscalController
{
  /**
   * Processa os impostos específicos do Simples Nacional
   */
  protected function processarImpostosProduto($produto, $index)
  {
    $item = $index + 1;

    // Verificar se é combustível
    if (isset($produto['codigo_anp']) && !empty($produto['codigo_anp'])) {
      $this->nfe->tagcomb($this->addCombustivelTag($produto, $index));
      $this->nfe->tagICMS($this->addICMSCombTag($produto, $index));
      $this->baseTotalIcms += 1000.00;
    } else {
      // ICMS do Simples Nacional (OBRIGATÓRIO)
      $icmssnData = $this->generateIcmssnData($produto, $item);
      $this->nfe->tagICMSSN($icmssnData);

      // Atualizar base de cálculo total para CSOSNs que possuem
      if (in_array($icmssnData->CSOSN, ['201', '202', '203', '900']) && isset($icmssnData->vBC)) {
        $this->baseTotalIcms += $icmssnData->vBC;
      }
    }

    $this->nfe->tagPIS($this->generatePisDataSimple($produto, $item));
    $this->nfe->tagCOFINS($this->generateConfinsDataSimple($produto, $item));

    $this->nfe->tagIBSCBS($this->generateIBSCBSData($produto, $item));
  }

  /**
   * Gera dados do ICMS para Simples Nacional
   */
  protected function generateIcmssnData($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->orig = isset($produto['origem']) ? $produto['origem'] : 0;
    $std->CSOSN = isset($produto['csosn']) ? $produto['csosn'] : '102';

    switch ($std->CSOSN) {
      case '101': // Tributada pelo Simples Nacional com permissão de crédito
        $std->pCredSN = isset($produto['aliquota_credito']) ? $produto['aliquota_credito'] : 3.00;
        $std->vCredICMSSN = number_format($this->baseCalculo * ($std->pCredSN / 100), 2, ".", "");
        break;

      case '102': // Tributada pelo Simples Nacional sem permissão de crédito
      case '103': // Isenção do ICMS no Simples Nacional para faixa de receita bruta
      case '300': // Imune
      case '400': // Não tributada pelo Simples Nacional
        // Nenhum campo adicional necessário para estes CSOSNs
        break;

      case '201': // Com permissão de crédito e com cobrança do ICMS por ST
      case '202': // Sem permissão de crédito e com cobrança do ICMS por ST
      case '203': // Isenção do ICMS e com cobrança do ICMS por ST
        $std->modBCST = 4; // Margem Valor Agregado
        $std->pMVAST = isset($produto['mva']) ? $produto['mva'] : 0.00;
        $std->pRedBCST = isset($produto['reducao_st']) ? $produto['reducao_st'] : 0.00;
        $std->vBCST = isset($produto['base_st']) ? $produto['base_st'] : 0.00;
        $std->pICMSST = isset($produto['aliquota_st']) ? $produto['aliquota_st'] : 0.00;
        $std->vICMSST = isset($produto['valor_st']) ? $produto['valor_st'] : 0.00;

        if ($std->CSOSN == '201') {
          $std->pCredSN = isset($produto['aliquota_credito']) ? $produto['aliquota_credito'] : 3.00;
          $std->vCredICMSSN = number_format($this->baseCalculo * ($std->pCredSN / 100), 2, ".", "");
        }
        break;

      case '500': // ICMS cobrado anteriormente por substituição tributária
        $std->vBCSTRet = isset($produto['base_retida']) ? $produto['base_retida'] : 0.00;
        $std->pST = isset($produto['aliquota_st_retida']) ? $produto['aliquota_st_retida'] : 0.00;
        $std->vICMSSTRet = isset($produto['valor_st_retido']) ? $produto['valor_st_retido'] : 0.00;
        break;

      case '900': // Outros
        $std->modBC = 3;
        $std->vBC = $this->baseCalculo;
        $std->pRedBC = isset($produto['reducao']) ? $produto['reducao'] : 0.00;
        $std->pICMS = isset($produto['aliquota']) ? $produto['aliquota'] : 0.00;
        $std->vICMS = $this->baseCalculo * ($std->pICMS / 100);

        // ST se houver
        if (isset($produto['st']) && $produto['st'] === true) {
          $std->modBCST = 4;
          $std->pMVAST = isset($produto['mva']) ? $produto['mva'] : 0.00;
          $std->pRedBCST = isset($produto['reducao_st']) ? $produto['reducao_st'] : 0.00;
          $std->vBCST = isset($produto['base_st']) ? $produto['base_st'] : 0.00;
          $std->pICMSST = isset($produto['aliquota_st']) ? $produto['aliquota_st'] : 0.00;
          $std->vICMSST = isset($produto['valor_st']) ? $produto['valor_st'] : 0.00;
        }

        // Crédito
        $std->pCredSN = isset($produto['aliquota_credito']) ? $produto['aliquota_credito'] : 0.00;
        $std->vCredICMSSN = $this->baseCalculo * ($std->pCredSN / 100);
        break;
    }

    // Cálculo do valor do ICMS para fins de totalização
    if (in_array($std->CSOSN, ['101', '201', '900']) && isset($std->vCredICMSSN)) {
      $this->valorIcms = $std->vCredICMSSN;
    }

    // $this->totalImposto += floatval($std->vCredICMSSN);
    // $this->totalImposto += floatval($std->vICMS);
    // $this->totalImposto += floatval($std->vICMSST);
    // $this->totalImposto += floatval($std->vBCST);

    return $std;
  }

  /**
   * Gera dados de PIS simplificados para Simples Nacional
   */
  protected function generatePisDataSimple($produto, $item)
  {
    $aliquotaPIS = $produto['aliquota_pis'] ?? 0.00;
    $valorPis = $produto['total'] * ($aliquotaPIS / 100);
    $cst = $produto['cst_pis'] ?? '06';

    $cst = UtilsController::validaCST($cst) ? $cst : '06';

    $std = new stdClass();
    $std->item = $item;
    $std->CST = $cst; // Operação Tributável - Alíquota Zero
    $std->vBC = number_format($produto['total'], 2, ".", "");
    $std->pPIS = number_format($aliquotaPIS, 2, ".", "");
    $std->vPIS = number_format($valorPis, 2, ".", "");

    $this->totalPIS += floatval($std->vPIS);
    $this->totalImposto += floatval($std->vPIS);

    return $std;
  }

  protected function generateIBSCBSData(array $produto, int $item)
  {
    $std = new stdClass();

    // Obrigatórios
    $std->item       = $item;
    $std->CST        = $produto['cst_ibscbs'] ?? '000';
    $std->cClassTrib = $produto['cclasstrib_ibscbs'] ?? '000001';

    // Base de cálculo
    $std->vBC = $this->baseCalculo;

    /**
     * ==============================
     * IBS - Competência da UF
     * ==============================
     */
    $std->gIBSUF_pIBSUF  = $this->aliquotaIbsEstadual ?? 0.10;
    $std->gIBSUF_vIBSUF  = number_format(
      $std->vBC * ($std->gIBSUF_pIBSUF / 100),
      2,
      '.',
      ''
    );

    /**
     * ==============================
     * IBS - Competência do Município
     * ==============================
     */
    $std->gIBSMun_pIBSMun = $this->aliquotaIbsMunicipal ?? 0.00;
    $std->gIBSMun_vIBSMun = number_format(
      $std->vBC * ($std->gIBSMun_pIBSMun / 100),
      2,
      '.',
      ''
    );

    /**
     * ==============================
     * CBS - Federal
     * ==============================
     */
    $std->gCBS_pCBS = $this->aliquotaCbs ?? 0.9000;
    $std->gCBS_vCBS = number_format(
      $std->vBC * ($std->gCBS_pCBS / 100),
      2,
      '.',
      ''
    );

    $this->totalIBS += floatval($produto['total']) + floatval($std->gIBSUF_vIBSUF) + floatval($std->gIBSMun_vIBSMun) + floatval($std->gCBS_vCBS);

    return $std;
  }

  /**
   * Gera dados de COFINS simplificados para Simples Nacional
   */
  protected function generateConfinsDataSimple($produto, $item)
  {
    $aliquotaCOFINS = $produto['aliquota_cofins'] ?? 0.00;
    $valorCOFINS = $produto['total'] * ($aliquotaCOFINS / 100);
    $cst = $produto['cst_cofins'] ?? '06';

    $std = new stdClass();
    $std->item = $item;
    $std->CST = $cst; // Operação Tributável - Alíquota Zero
    $std->vBC = number_format($produto['total'], 2, ".", "");
    $std->pCOFINS = number_format($aliquotaCOFINS, 2, ".", "");
    $std->vCOFINS = number_format($valorCOFINS, 2, ".", "");

    $this->totalCOFINS += floatval($std->vCOFINS);
    $this->totalImposto += floatval($std->vCOFINS);

    return $std;
  }
}
