<?php

namespace App\Controllers\Fiscal;

use App\Controllers\UtilsController;
use App\Http\JsonResponse;
use App\Models\CompanyModel;
use App\Models\EmissoesModel;
use Divulgueregional\ApiNfse\NFSeNacional;

/**
 * Controller para emissão de NFS-e (Nota Fiscal de Serviços Eletrônica)
 *
 * Utiliza a API Nacional do Sistema NFS-e para emissão de notas de serviço.
 * Aceita o mesmo body da emissão de NFe, mapeando os campos para o padrão NFS-e.
 */
class NotaServicoController
{
    protected $company;
    protected $ambiente;
    protected $serie;
    protected $numero;
    protected $data;
    protected $nfse;

    public function __construct($data = null)
    {
        if ($data) {
            $this->data = $data;
        }
    }

    /**
     * Carrega os dados da empresa e inicializa a série/número para NFS-e.
     */
    protected function bootstrapCompany(string $cnpj): bool
    {
        $companyModel = new CompanyModel();
        $companies = $companyModel->find([
            'cnpj' => UtilsController::soNumero($cnpj)
        ]);

        if (!$companies) {
            return false;
        }

        $this->company = new CompanyModel($companies[0]['id']);
        $this->ambiente = intval($this->company->getTpamb()) > 0
            ? intval($this->company->getTpamb())
            : 2;

        // Reutiliza série/número da NFe como base para NFS-e (sem campo dedicado no banco)
        $this->serie = $this->ambiente === 1
            ? $this->company->getSerie_nfse()
            : $this->company->getSerie_nfse_homologacao();

        $this->numero = $this->ambiente === 1
            ? $this->company->getNumero_nfse()
            : $this->company->getNumero_nfse_homologacao();

        return true;
    }

    /**
     * Retorna o caminho absoluto do certificado digital da empresa.
     */
    protected function getCertPath(): string
    {
        $path = dirname(__DIR__, 3)
            . '/app/storage/certificados/'
            . basename((string) $this->company->getCertificado());

        if (!is_file($path)) {
            throw new \RuntimeException('Certificado da empresa não encontrado.');
        }

        return $path;
    }

    /**
     * Emite uma NFS-e com base no payload recebido.
     */
    public function emitir(): void
    {
        if (empty($this->data) || !isset($this->data['cnpj'])) {
            JsonResponse::error('Payload inválido para emissão de NFS-e.', 400);
            return;
        }

        try {
            if ($this->bootstrapCompany($this->data['cnpj']) === false) {
                JsonResponse::error('Empresa não encontrada.', 404);
                return;
            }

            if (floatval($this->data['total'] ?? 0) <= 0) {
                JsonResponse::error('O valor total do serviço precisa ser maior que zero.', 422);
                return;
            }

            $config = [
                'cert_path' => $this->getCertPath(),
                'cert_password' => $this->company->getSenha()
            ];

            $this->nfse = new NFSeNacional($config, $this->ambiente);

            $municipio = $this->company->getCodigo_municipio();
            $cnpj = $this->company->getCnpj();
            $serie = intval($this->data['servico']['serie'] ?? $this->serie);
            $numero = intval($this->data['servico']['numero'] ?? $this->numero);
            if ($serie < 1 || $numero < 1) {
                JsonResponse::error('Série e número da NFS-e precisam ser maiores que zero.', 422);
                return;
            }
            $this->serie = $serie;
            $this->numero = $numero;

            $dados = $this->buildDados($municipio, $cnpj, $serie, $numero);
            $xmlBruto = $this->nfse->montarXmlDPS($dados);
            $xmlAssinado = $this->nfse->assinarXML($xmlBruto);
            $retorno = $this->nfse->enviarDPS($xmlAssinado);

            if (isset($retorno['codigo']) && (int) $retorno['codigo'] === 201) {
                $this->atualizaNumero();
                $this->salvaEmissao($retorno, $dados['idDps'], $serie, $numero);

                JsonResponse::send([
                    'chave' => $retorno['chaveAcesso'] ?? $dados['idDps'],
                    'protocolo' => $retorno['idDps'] ?? '',
                    'avisos' => $retorno['alertas'] ?? [],
                    'xml' => $retorno['xmlNfse'] ?? $xmlAssinado,
                    'retorno' => $retorno
                ]);
            } else {
                JsonResponse::send([
                    'error' => 'Erro ao emitir NFS-e',
                    'xml' => $xmlAssinado,
                    'retorno' => $retorno
                ], 422);
            }
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            JsonResponse::error('Erro interno ao emitir NFS-e.', 500);
        }
    }

    /**
     * Monta o array de dados para a DPS a partir do payload no formato NFe.
     */
    protected function buildDados(string $municipio, string $cnpj, int $serie, int $numero): array
    {
        $data = $this->data;
        $servicoInput = $data['servico'] ?? [];
        $fiscal = $data['fiscal'] ?? [];

        $idDps = $this->gerarIdDPS($municipio, $cnpj, $serie, $numero);

        return [
            'idDps' => $idDps,
            'dhEmi' => date('Y-m-d\TH:i:sP', strtotime('-1 minute')),
            'verAplic' => 'SaaS_1.0',
            'serie' => $serie,
            'nDps' => $numero,
            'dCompet' => $servicoInput['dCompet'] ?? date('Y-m-d'),
            'tpEmit' => '1',
            'cLocEmi' => $municipio,

            'prestador' => $this->buildPrestador($fiscal),
            'tomador' => $this->buildTomador($data['cliente'] ?? []),

            'servico' => [
                'cLocPrestacao' => $servicoInput['cLocPrestacao'] ?? $municipio,
                'cTribNac' => $servicoInput['cTribNac'] ?? '010701',
                'xDescServ' => $servicoInput['xDescServ'] ?? $this->extrairDescricaoServico($data),
                'xInfComp' => $servicoInput['xInfComp'] ?? ($data['observacao'] ?? ''),
                'cIntContrib' => $servicoInput['cIntContrib'] ?? ''
            ],

            'valores' => [
                'vServ' => floatval($data['total'] ?? 0),
                'tribISSQN' => intval($servicoInput['tribISSQN'] ?? 1),
                'tpRetISSQN' => intval($servicoInput['tpRetISSQN'] ?? 1),
                'pTotTribSN' => floatval($servicoInput['pTotTribSN'] ?? 0.00)
            ]
        ];
    }

    /**
     * Monta os dados do prestador com regime tributário baseado no CRT da empresa.
     */
    protected function buildPrestador(array $fiscal): array
    {
        return [
            'cnpj' => $this->company->getCnpj(),
            'im' => $this->company->getInscricao_municipal() ?? '',
            'fone' => UtilsController::soNumero($this->company->getTelefone() ?? ''),
            'email' => $this->company->getEmail() ?? '',
            'regTrib' => $this->buildRegTrib($fiscal)
        ];
    }

    /**
     * Mapeia os dados do cliente (NFe) para o tomador (NFS-e).
     */
    protected function buildTomador(array $cliente): array
    {
        if (empty($cliente)) {
            return [];
        }

        $tomador = [];

        if (strtoupper($cliente['tipo_documento'] ?? '') === 'CPF') {
            $tomador['cpf'] = UtilsController::soNumero($cliente['documento'] ?? '');
        } else {
            $tomador['cnpj'] = UtilsController::soNumero($cliente['documento'] ?? '');
        }

        $tomador['xNome'] = $cliente['nome'] ?? '';
        $tomador['fone'] = UtilsController::soNumero($cliente['telefone'] ?? '');
        $tomador['email'] = $cliente['email'] ?? '';

        if (!empty($cliente['endereco'])) {
            $endereco = $cliente['endereco'];
            $tomador['endereco'] = [
                'cMun' => $endereco['codigo_municipio'] ?? '',
                'cep' => UtilsController::soNumero($endereco['cep'] ?? ''),
                'xLgr' => $endereco['logradouro'] ?? '',
                'nro' => $endereco['numero'] ?? 'SN',
                'xBairro' => $endereco['bairro'] ?? ''
            ];
        }

        return $tomador;
    }

    /**
     * Monta o regime tributário do prestador com base no CRT da empresa.
     * Permite sobrescrever via campo `fiscal` no payload.
     */
    protected function buildRegTrib(array $fiscal): array
    {
        $crt = intval($this->company->getCrt());
        $opSimpNac = 1; // Não Optante (padrão Regime Normal)
        $regApTribSN = null;
        $regEspTrib = 0;

        switch ($crt) {
            case 1: // Simples Nacional
                $opSimpNac = 3; // Optante ME/EPP
                $regApTribSN = 1;
                break;
            case 4: // MEI
                $opSimpNac = 2;
                $regApTribSN = 1;
                break;
            case 2: // Simples com Excesso de Sublimite
                $opSimpNac = 3;
                $regApTribSN = 2;
                break;
            case 3: // Regime Normal (Lucro Presumido/Real)
            default:
                $opSimpNac = 1;
                break;
        }

        // Sobrescreve via campo `fiscal` no payload, se informado
        if (isset($fiscal['opSimpNac']))
            $opSimpNac = intval($fiscal['opSimpNac']);
        if (isset($fiscal['regApTribSN']))
            $regApTribSN = intval($fiscal['regApTribSN']);
        if (isset($fiscal['regEspTrib']))
            $regEspTrib = intval($fiscal['regEspTrib']);

        $regTrib = [
            'opSimpNac' => $opSimpNac,
            'regEspTrib' => $regEspTrib
        ];

        if ($regApTribSN !== null) {
            $regTrib['regApTribSN'] = $regApTribSN;
        }

        return $regTrib;
    }

    /**
     * Concatena as descrições dos produtos para usar como descrição do serviço.
     */
    protected function extrairDescricaoServico(array $data): string
    {
        if (!empty($data['produtos'])) {
            $descricoes = array_map(fn($p) => $p['descricao'] ?? '', $data['produtos']);
            return implode('; ', array_filter($descricoes));
        }

        return '';
    }

    /**
     * Incrementa o número da NFS-e no cadastro da empresa.
     */
    protected function atualizaNumero(): void
    {
        if ($this->ambiente === 1) {
            $this->company->setNumero_nfse(intval($this->numero) + 1);
            $this->company->update(['numero_nfse' => $this->company->getNumero_nfse()]);
        } else {
            $this->company->setNumero_nfse_homologacao(intval($this->numero) + 1);
            $this->company->update([
                'numero_nfse_homologacao' => $this->company->getNumero_nfse_homologacao()
            ]);
        }
    }

    /**
     * Persiste a emissão no banco de dados.
     */
    protected function salvaEmissao(array $retorno, string $idDps, int $serie, int $numero): void
    {
        $emissao = new EmissoesModel();
        $emissao->setChave($retorno['chaveAcesso'] ?? $idDps);
        $emissao->setNumero($numero);
        $emissao->setSerie($serie);
        $emissao->setEmpresa($this->company->getCnpj());
        $emissao->setXml($retorno['xmlNfse'] ?? '');
        $emissao->setPdf('');
        $emissao->setTipo('NFSE');
        $emissao->setProtocolo($retorno['idDps'] ?? '');
        $emissao->create();
    }

    private function gerarIdDPS(string $cMun, string $cnpjCpf, int $serie, int $nDps): string
    {
        $cnpjCpf = preg_replace('/\D/', '', $cnpjCpf);

        $tpInsc = strlen($cnpjCpf) === 14 ? '1' : '2';

        $cnpjCpf = str_pad($cnpjCpf, 14, '0', STR_PAD_LEFT);
        $serieStr = str_pad((string) $serie, 5, '0', STR_PAD_LEFT);
        $nDpsStr = str_pad((string) $nDps, 15, '0', STR_PAD_LEFT);

        return "DPS{$cMun}{$tpInsc}{$cnpjCpf}{$serieStr}{$nDpsStr}";
    }
}

