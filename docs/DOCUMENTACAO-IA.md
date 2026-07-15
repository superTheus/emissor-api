# EMISSOR-API — contexto canônico para IA

```yaml
document_kind: repository-context
audience: AI coding agents, reviewers, maintainers
language: pt-BR
snapshot_date: 2026-07-15
base_snapshot_commit: 851af55
current_code_commit_reviewed: 6997428
analysis_type: static, complete for app/Controllers + app/Models + app/Routers
runtime_side_effects_executed: false
database_schema_status: inferred_from_models; configured DB host unavailable
fiscal_warning: behavior is documented; fiscal correctness is not certified
current_override: docs/MELHORIAS-LEGADO.md
precedence: MELHORIAS-LEGADO.md overrides changed behavior in this pre-refactor snapshot
```

## 0. Regras de interpretação

1. O código e `docs/MELHORIAS-LEGADO.md` são as fontes vigentes; este arquivo preserva o mapa profundo do snapshot anterior à refatoração.
2. Autenticação opcional por `API_TOKEN` existe no código vigente; não há escopos de autorização, rate limit, migrations ou OpenAPI.
3. Não emita/cancele documento real para testar; há efeito fiscal externo.
4. Respostas nem sempre são JSON válido: há closures/erros em texto e Models imprimem erros PDO.
5. Preserve URLs grafadas no código, inclusive `/fiscal/certicate`.
6. Não invente tipos/constraints SQL. Só nomes de tabelas/colunas estão confirmados pelos Models.
7. `tpamb === 1` seleciona produção; outros valores usam campos de homologação na maioria dos fluxos.
8. CNPJ é normalmente normalizado por `UtilsController::soNumero()`; filtros genéricos não normalizam, exceto `EmissoesModel` na chave `empresa`.

## 1. Identidade do sistema

```yaml
entrypoint: index.php
router: App\Routers\Routers::execute
autoload: PSR-4 App\ => app/
timezone: America/Manaus
request_format: JSON para endpoints de negócio
response_header: application/json definido em Routers.php
cors:
  origin: '*'
  methods: [GET, POST, PATCH, PUT, DELETE, OPTIONS]
authentication:
  mode: optional shared API token
  disabled_when: API_TOKEN empty
  accepted_headers: [Authorization Bearer, X-Auth-Token]
storage:
  certificates: app/storage/certificados
  company_logos: app/storage/logos
  fiscal_xml: app/storage/fiscal/xml
  fiscal_pdf: app/storage/fiscal/pdf
  preview_xml: app/storage/fiscal/xml/preview
  preview_pdf: app/storage/fiscal/pdf/preview
environment: [DB_SERVER, DB_NAME, DB_USER, DB_PASS, URL_BASE]
dependencies:
  bramus/router: 1.6.1
  vlucas/phpdotenv: 5.6.3
  nfephp-org/sped-nfe: dev-master@c0756c2
  nfephp-org/sped-da: dev-master@1fdf9dd
  divulgueregional/api-nfse: 1.1.4
  dompdf/dompdf: 3.1.5
```

## 2. Grafo de componentes

```text
index.php
└── Routers::execute
    ├── CompanyController ────────────── CompanyModel ── empresa
    ├── FiscalController
    │   ├── CRT1Controller ┐
    │   ├── CRT2Controller ├─ BaseFiscalController ─ NFePHP/SEFAZ
    │   ├── CRT3Controller ┤       ├─ CompanyModel
    │   └── CRT4Controller ┘       ├─ FormaPagamentoModel
    │                              ├─ EmissoesModel
    │                              └─ EmissoesEventosModel
    ├── CupomFiscalController ───── NFePHP/SEFAZ + Models centrais
    ├── NotaServicoController ───── api-nfse/NFS-e Nacional + Models centrais
    ├── EmissoesController ──────── EmissoesModel / certificado
    ├── Estados/MunicipiosController
    └── controllers de catálogo ─── models de catálogo
```

Dispatch CRT:

```yaml
1: App\Controllers\Fiscal\CRT1Controller
2: App\Controllers\Fiscal\CRT2Controller
3: App\Controllers\Fiscal\CRT3Controller
4: App\Controllers\Fiscal\CRT4Controller
other: HTTP 400 + exit
company_not_found: HTTP 404 + exit
missing_request_or_cnpj: HTTP 400 + exit
```

## 3. Registro completo das 35 rotas

| # | Método | Path | Target | Entrada | Sucesso |
|---:|---|---|---|---|---|
| 1 | GET | `/` | closure | nenhuma | `Home` texto |
| 2 | POST | `/` | closure | ignorada | `Home` texto |
| 3 | GET | `/company/` | closure | nenhuma | `Empresa` texto |
| 4 | POST | `/company/` | closure | ignorada | `Empresa` texto |
| 5 | POST | `/company/list` | `CompanyController::find` | `SearchEnvelope` | rows + `dados_certificado` + `logo_url` |
| 6 | POST | `/company/create` | `CompanyController::create` | `CompanyCreate`, logo opcional | empresa + `logo_url` |
| 7 | PUT | `/company/update/{id}` | `CompanyController($id)::update` | empresa parcial, logo opcional/null | empresa + `logo_url` |
| 8 | GET | `/fiscal/` | closure | nenhuma | `Fiscal` texto |
| 9 | GET | `/fiscal/nfe/` | closure | nenhuma | `Emitir NFe` texto |
| 10 | POST | `/fiscal/nfe/` | `FiscalController::createNfe(false)` | `NFeRequest` | `FiscalDocumentResponse` |
| 11 | POST | `/fiscal/nfe/cancel` | `FiscalController::cancelNfe` | `CancelRequest` | `NFeCancelResponse`; atualmente bloqueada pelo validador de emissão |
| 12 | POST | `/fiscal/nfe/carta` | `FiscalController::gerarCC` | `CCeRequest` | `CCeResponse`; atualmente bloqueada pelo validador de emissão |
| 13 | POST | `/fiscal/nfe/preview` | `FiscalController::createNfe(true)` | `NFeRequest` | preview response |
| 14 | POST | `/fiscal/nfce/` | `CupomFiscalController::createNfe` | `NFCeRequest` | `FiscalDocumentResponse` |
| 15 | POST | `/fiscal/nfce/cancel` | `CupomFiscalController::cancelNfce` | `CancelRequest` | `NFCeCancelResponse` com protocolo/XML |
| 16 | GET | `/fiscal/nfse/` | closure | nenhuma | `Emitir NFS-e` texto |
| 17 | POST | `/fiscal/nfse/` | `NotaServicoController::emitir` | `NFSeRequest` | `NFSeResponse` |
| 18 | POST | `/fiscal/emissoes` | `EmissoesController::find({filter: body})` | filtro cru | emissões[] |
| 19 | GET | `/fiscal/certicate/` | closure | nenhuma | `Certificado` texto |
| 20 | POST | `/fiscal/certicate/test` | `EmissoesController::verifyCertificate` | `CertificateTest` | empresa/cnpj/validade; não persiste |
| 21 | GET | `/fiscal/certicate/test/{cnpj}` | `UtilsController::testCertificate` | path CNPJ | metadados do PFX salvo |
| 22 | POST | `/cest/` | `CestController::find` | `SearchEnvelope` | rows[] |
| 23 | POST | `/cfop/` | `CfopController::find` | `SearchEnvelope` | rows[] |
| 24 | POST | `/formas/` | `FormaPagamentoController::find` | `SearchEnvelope` | rows[] |
| 25 | POST | `/ibpt/` | `IbptController::find` | `SearchEnvelope` | rows[] |
| 26 | POST | `/ncm/` | `NcmController::find` | `SearchEnvelope` | rows[] |
| 27 | POST | `/origem/` | `OrigemController::find` | `SearchEnvelope` | rows[] |
| 28 | POST | `/situacao/` | `SituacaoTributariaController::find` | `SearchEnvelope` | rows[] |
| 29 | POST | `/unidades/` | `UnidadesController::find` | `SearchEnvelope`; limit ignorado | rows ou 404 |
| 30 | POST | `/estados/` | `EstadosController::find` | `SearchEnvelope` | objeto se 1, senão array |
| 31 | POST | `/estados/{uf}` | mesmo | path → filtro | objeto ou `[]` |
| 32 | GET | `/estados/{uf}` | `EstadosController::findunique` | path | primeiro ou 401 texto |
| 33 | POST | `/municipios/` | `MunicipiosController::find` | `SearchEnvelope` | rows[] |
| 34 | GET | `/municipios/{uf}/{cidade}` | estado + `findunique` | path exato | primeiro; erros 404/401 |
| 35 | POST | `/municipios/{uf}` | `MunicipiosController::findByUf` | path | municípios[] |

Router 404: status 404, body texto `404, Rota não encontrada`.

## 4. Contratos de entrada

### 4.1 SearchEnvelope

```json
{"filter":{"database_column":"exact value"},"limit":10}
```

```yaml
operator: equality only
join: AND
column_allowlist: absent
value_binding: PDO em todos, exceto UnidadesModel
empty_filter: SELECT all rows
```

### 4.2 CompanyCreate

Todas as chaves abaixo são acessadas diretamente pelo `INSERT`:

```yaml
CompanyCreate:
  tpamb: int|string
  cnpj: string
  razao_social: string
  nome_fantasia: string
  telefone: string
  email: string
  cep: string
  logradouro: string
  numero: string
  bairro: string
  cidade: string
  uf: string
  certificado: base64 PFX; data URI aceita
  senha: string
  csc: string
  csc_id: string
  serie_nfce: int
  numero_nfce: int
  serie_nfe: int
  numero_nfe: int
  codigo_municipio: string
  codigo_uf: string
  situacao_tributaria: string
  inscricao_estadual: string
  csc_homologacao: string
  csc_id_homologacao: string
  serie_nfce_homologacao: int
  numero_nfce_homologacao: int
  serie_nfe_homologacao: int
  numero_nfe_homologacao: int
  crt: 1|2|3|4
  serie_nfse: int
  numero_nfse: int
  serie_nfse_homologacao: int
  numero_nfse_homologacao: int
not_inserted_on_create: [cnae, inscricao_municipal, atividade]
```

Certificado vigente: remove prefixo `base64,` → valida Base64 estrito → salva PFX → abre via fluxo OpenSSL compatível → extrai documento de `subject.CN` → compara CNPJ → verifica início/fim da validade → escreve no banco. Arquivo novo é removido em falha e o antigo é removido depois de substituição bem-sucedida.

Logo vigente:

```yaml
request_field: logo
encoding: Base64 puro ou data:image/{png|jpeg|webp};base64,...
optional_on_create: true
update_semantics:
  omitted: preserve
  string: validate, save new, persist filename, then remove old
  null: persist null, then remove old
validation:
  detected_mime: [image/png, image/jpeg, image/webp]
  max_decoded_bytes: 2097152
  dimensions: 1..4096 em cada eixo
storage: app/storage/logos/logo_{random_bytes_16_hex}.{png|jpg|webp}
database: empresa.logo VARCHAR(255) NULL
response: logo_url; internal logo filename omitted
migration: docs/MIGRACAO-LOGO-EMPRESA.md
fiscal_pdf_integration: false
```

### 4.3 NFeRequest

```yaml
required_root: [cnpj, cfop, cliente, produtos]
optional_root:
  operacao: default VENDA DE MERCADORIA
  finalidade: default 1
  consumidor_final: S => indFinal 1; PF força 1
  modoEmissao: default 1
  total: usado na inicialização; bases por produto o substituem
  total_acrescimo: number
  total_desconto: number
  total_frete: number
  desconto: totalizador tenta usar, depois sobrescreve vDesc com 0
  troco: number
  observacao: string
  nota_referencia: NFe access key
  modalidade_frete: default 9
  transportadora: object
  quantidade_volumes: number
  especie_volume: string
  peso_liquido: number
  peso_bruto: number
  cnpj_consulta: default 13937073000156
  pagamentos: Payment[]
  fiscal: FiscalRates
Client:
  required: [nome, tipo_documento, documento, endereco]
  tipo_documento: CPF => PF; outros => PJ/CNPJ
  optional: [inscricao_estadual, tipo_icms]
  endereco_required: [logradouro, numero, bairro, codigo_municipio, municipio, uf, cep]
NFeProduct:
  required: [codigo, ean, descricao, ncm, cfop, unidade, quantidade, valor, total, desconto, origem]
  optional: [frete, outras_despesas, informacoes_adicionais]
Payment:
  codigo: deve existir em formas_pagtosefaz.codigo
  valorpago: number
FiscalRates:
  aliquota_ibs_estadual: default real 0
  aliquota_ibs_municipal: default real 0
  aliquota_cbs: default real 0
```

Fórmulas:

```text
base = produto.total - produto.desconto + produto.frete + produto.outras_despesas(default 0)
frete ausente = produto.total / sum(produto.valor*produto.quantidade) * total_frete
```

#### Extensão CRT 1

```yaml
cst_icms: interpretado como CSOSN; fallback 102
aliquota_credito: 101/201/900
ST: [mva, reducao_st, base_st, aliquota_st, valor_st]
ST_retido: [base_retida, aliquota_st_retida, valor_st_retido]
CSOSN_900: [mod_bc_icms, reducao, aliquota_icms, st]
PIS: [cst_pis default 06, aliquota_pis default 0]
COFINS: [cst_cofins default 06, aliquota_cofins default 0]
IBSCBS: [cst_ibscbs default 000, cclasstrib_ibscbs default 000001]
tags: [ICMSSN, PIS, COFINS, IBSCBS]
```

Acumulador real: `totalIBS += produto.total + IBSUF + IBSMun + CBS`, depois emitido como `vIBS` (suspeito).

#### Extensão CRT 2

```yaml
icms_if_present:
  required: [aliquota_icms, cst]
  optional: [mod_bc default 0]
without_icms: CST 40
PIS_COFINS: CST 06, zero
```

#### Extensão CRT 3

```yaml
icms:
  required_if_present: [aliquota_icms, cst]
  optional: [mod_bc, reducao, st, mod_bc_st, mva, reducao_st, base_st, aliquota_st, valor_st, fcp]
ipi:
  required_if_present: [aliquota_ipi, cst]
  optional: [enquadramento_legal_ipi default 999]
pis: {required_if_present: [aliquota_pis, cst]}
cofins: {required_if_present: [aliquota_cofins, cst]}
defaults: ICMS cst_icms ou 40; PIS/COFINS CST 07 zero
```

#### Extensão CRT 4

```yaml
csosn: aceita [102, 103, 300, 400, 500]; outro => 102
for_500: [base_retida, aliquota_st_retida, valor_st_retido]
PIS_COFINs: CST 06, zero
emit_CRT: força string '4'
```

#### Combustível, todos os CRTs

`codigo_anp` não vazio ativa o ramo. Campos diretos: `codigo_anp`, `descricao_anp`, `gpl_percentual`, `gas_percentual_nacional`, `valor_partida`, `quantidade`. ICMS é hardcoded com valores de exemplo, não calculado.

### 4.4 NFCeRequest

```yaml
required_root_by_access: [cnpj, cfop, produtos, pagamentos]
produtos: nonempty
pagamentos: nonempty
cliente: opcional para tagdest; se documento existe, espera nome/tipo_documento
cliente.endereco: opcional; só para nome diferente de CONSUMIDOR FINAL
produto_required: [codigo, ean, descricao, ncm, cfop, unidade, quantidade, valor, total, origem]
produto_optional: [desconto default 0, frete default 0, acrescimo default 0, informacoes_adicionais]
payment_required: [codigo, valorpago]
root_optional: [operacao, modoEmissao, troco]
fixed:
  model: 65
  idDest: 1
  tpImp: 4
  finNFe: 1
  indFinal: 1
  indPres: 1
  modFrete: 9
common_product_tax: ICMSSN; não há dispatch por CRT
```

### 4.5 NFSeRequest

```yaml
explicitly_required: [cnpj]
total: default 0 => valores.vServ
observacao: fallback xInfComp
produtos: descrições concatenadas para xDescServ
cliente: opcional; vazio => tomador=[]
servico:
  serie: override opcional
  numero: override opcional
  dCompet: default hoje
  cLocPrestacao: default município emissor
  cTribNac: default '010701'
  xDescServ: default produtos[].descricao unidos por '; '
  xInfComp: default observacao
  cIntContrib: default ''
  tribISSQN: default 1
  tpRetISSQN: default 1
  pTotTribSN: default 0
fiscal_override: [opSimpNac, regApTribSN, regEspTrib]
```

Tomador: CPF se `tipo_documento === CPF`; demais viram CNPJ. Mapeia nome/telefone/e-mail e endereço `codigo_municipio,cep,logradouro,numero,bairro`.

Campos DPS:

```yaml
idDps: DPS + cMun + tpInsc + documento14 + serie5 + nDps15
dhEmi: now minus 1 minute
verAplic: SaaS_1.0
tpEmit: '1'
cLocEmi: company.codigo_municipio
```

### 4.6 Eventos e certificado

```yaml
CancelRequest:
  required: [cnpj, chave, justificativa]
  cnpj: string; normalizado para busca da empresa
  chave: chave de acesso com 44 dígitos; implementação exige emissão local
  justificativa: 15..255 no leiaute; código valida apenas mínimo 15
  applies_to: [POST /fiscal/nfe/cancel, POST /fiscal/nfce/cancel]
CCeRequest:
  required: [cnpj, chave, carta]
  chave: chave modelo 55 existente em emissoes com tipo=NFE
  carta: 15..1000; deve consolidar todas as CC-e anteriores
  sequence: emissoes.sequencia_cc + 1; limite técnico 1..20 não pré-validado
  applies_to: [POST /fiscal/nfe/carta]
  not_available_for: NFC-e modelo 65
NFEventDispatchDefect:
  routes: [POST /fiscal/nfe/cancel, POST /fiscal/nfe/carta]
  cause: FiscalController::handlerFor instancia CRTxController(payload_evento); BaseFiscalController::__construct chama validateEmissionData
  current_result: payload correto pode retornar 422 exigindo cfop/cliente/produtos/total/pagamentos antes do método de evento
  forbidden_workaround: não enviar payload fictício de emissão
  required_fix: inicialização específica de evento sem validateEmissionData/initializeFromData
CertificateTest:
  certificado: string não vazia; conteúdo binário PFX/P12 em Base64 puro ou Data URL
  senha: string; pode ser vazia para PFX sem senha
EmissaoFilter:
  common: {empresa: CNPJ, tipo: NFE|NFCE|NFSE, chave: string}
  code_allows: qualquer nome de coluna SQL
```

## 5. Algoritmos e efeitos colaterais

### 5.1 NF-e

```text
FiscalController: valida CNPJ → busca empresa → escolhe CRT
BaseFiscalController: identifica PF/PJ → Make(10) → empresa/cert/Tools modelo 55
→ resolve pagamentos → sefazStatus (não 107: tpEmis 9 + warning) → chave
createNfe: tags ide/emit/dest → produtos+tributos CRT → totais/transporte/pagamento
→ preview: render/save e parar
→ emissão: assinar → enviar lote síncrono → tratar 100/103/104/105
→ protocolar → DANFE → salvar XML/PDF → incrementar número → inserir emissoes NFE
```

Preview grava arquivos de preview; não assina, envia, incrementa ou insere. Ainda carrega PFX e chama status SEFAZ.

```yaml
NFE_cancel:
  current_entry_blocker: NFEventDispatchDefect
  intended_flow: validate fields -> bootstrap company/certificate -> load emissoes by chave/type=NFE -> sefazCancela(chave, justificativa, protocolo) -> protocol event -> render original DANFE with cancel flag -> save PDF -> insert CANCELAMENTO
  accepted_by_code: outer/read cStat in [128, 135]
  persistence: emissoes_eventos {tipo: CANCELAMENTO, protocolo_evento, xml_evento, link_pdf}
  response_xml: XML original da NF-e, não XML do evento
  original_emissao_status_update: none
  post_sefaz_failure_risk: PDF/DB pode falhar e retornar 500 após cancelamento fiscal
NFE_CCe:
  current_entry_blocker: NFEventDispatchDefect
  intended_flow: validate fields -> load NFE -> sequence+1 -> sefazCCe -> protocol -> update sequence -> render DACCE -> insert CC
  accepted_by_code: outer/read cStat in [128, 135, 136]
  consolidation: caller responsibility; API não lê textos anteriores
  pdf_failure: HTTP 200 + warning/link/pdf vazios; sequência atualizada; evento não inserido localmente
  original_xml_totals: unchanged
```

### 5.2 NFC-e

```text
constructor: empresa/config/cert/CSC + Make + Tools 65 + pagamentos + chave
createNfe: tags fixas modelo 65 → destinatário opcional → produtos obrigatórios
→ ICMSSN (ou combustível) → totais → pagamentos obrigatórios
→ assinar/enviar/protocolar → DANFCE 80mm → arquivos
→ incrementar numero_nfce do ambiente → inserir emissoes NFCE
```

```yaml
NFCE_cancel:
  flow: validate fields/min15 -> load company/PFX -> load emissoes by chave/type=NFCE -> sefazCancela -> protocol -> insert CANCELAMENTO -> response
  accepted_by_code: outer/read cStat in [128, 135]
  persistence: emissoes_eventos {tipo: CANCELAMENTO, protocolo, xml, link: ''}
  response: {status, message, protocolo, xml_evento}
  generated_pdf: none
  original_emissao_status_update: none
  extemporaneous_AM: não implementa liberação/DAR/procedimento administrativo
  post_sefaz_failure_risk: DB pode falhar e retornar 500 após cancelamento fiscal
```

### 5.3 NFS-e

```text
validar cnpj → empresa → série/número NFSe do ambiente
→ NFSeNacional(PFX) → build DPS → XML → assinatura → enviarDPS
→ se retorno.codigo === integer 201: atualizaNumero [BUG: grava contador NFe]
→ inserir emissoes NFSE com pdf vazio → 200
→ senão 403 com XML assinado e retorno do provedor
```

## 6. Respostas e status

### FiscalDocumentResponse

applies_to: [`POST /fiscal/nfe/`, `POST /fiscal/nfce/`]

```json
{
  "chave": "string",
  "avisos": ["string"],
  "protocolo": "string|null",
  "link": "URL_BASE + caminho PDF",
  "xml": "string",
  "pdf": "base64"
}
```

NF-e success example:

```json
{
  "chave": "13260712345678000190550010000001231123456780",
  "avisos": [],
  "protocolo": "113260000000001",
  "link": "https://api.exemplo.com/app/storage/fiscal/pdf/pdf_...pdf",
  "xml": "<nfeProc>...</nfeProc>",
  "pdf": "JVBERi0xLjQK..."
}
```

NFC-e success example (homologation; `xml` and `pdf` shortened):

```json
{
  "chave": "31260717606607000112650360000000011020261003",
  "avisos": [],
  "protocolo": "131260000662453",
  "link": "http://projetos.local/emissor-api/app/storage/fiscal/pdf/pdf_31260717606607000112650360000000011020261003.pdf",
  "xml": "<?xml version=\"1.0\" encoding=\"UTF-8\"?><nfeProc versao=\"4.00\">...</nfeProc>",
  "pdf": "JVBERi0xLjMK..."
}
```

### SefazRejectionResponse

applies_to: [`POST /fiscal/nfe/`, `POST /fiscal/nfce/`]

HTTP status: `422`. `codigo` and `cStat` are SEFAZ status codes, not HTTP status codes.

```json
{
  "codigo": 204,
  "cStat": 204,
  "error": "Rejeição: Duplicidade de NF-e [nRec: 310000133336764]",
  "error_tags": [],
  "etapa": "autorização da SEFAZ"
}
```

```yaml
common_fiscal_status:
  200: documento processado localmente após autorização, ou preview
  202: lote ainda em processamento; resposta inclui cStat e recibo
  422: rejeição SEFAZ; resposta inclui codigo, cStat, error, error_tags e etapa
  500: exceção; pode conter XML parcial, erros de tags e CSC na NFCe
```

### Eventos de NF-e/NFC-e

Fontes/versionamento conferidos em `2026-07-15`:

```yaml
technical:
  MOC: '7.0'
  event_layout: '1.00'
legal:
  NFE: Ajuste SINIEF 07/2005 consolidado; cláusulas 12, 13 e 14-A
  NFCE: Ajuste SINIEF 19/2016 consolidado; cláusulas 13 e 15
  CCe: NT 2011.003 + Ajuste SINIEF 07/2005 cláusula 14-A
  Amazonas_NFCe: Resolução 0018/2022-GSEFAZ
source_urls:
  MOC: https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=ndIjl+iEFdE%3D
  NFE_legal: https://www.confaz.fazenda.gov.br/legislacao/ajustes/2005/AJ007_05
  NFCE_legal: https://www.confaz.fazenda.gov.br/legislacao/ajustes/2016/AJ_019_16
  CCe_NT: https://www.nfe.fazenda.gov.br/Portal/exibirArquivo.aspx?conteudo=hNJXbmu+l8Q%3D
  Amazonas: https://online.sefaz.am.gov.br/doe/toPdf.asp?idPublicacao=1502
```

```yaml
event_status_semantics:
  128: lote de evento processado; não prova autorização do evento
  135: evento registrado e vinculado ao documento
  136: evento registrado, mas não vinculado ao documento
required_interpretation: ler retEvento.infEvento.cStat após cStat externo 128
implementation_gap: cancelNfe/cancelNfce/gerarCC aceitam cStat externo/lido sem validar explicitamente o cStat interno
no_calculations_or_totals: true
original_authorized_xml_mutation: false
```

#### NFeCancelResponse

`POST /fiscal/nfe/cancel` — contrato pretendido após corrigir `NFEventDispatchDefect`.

```json
{
  "chave": "string",
  "avisos": [],
  "protocolo": "protocolo do evento",
  "link": "URL do DANFE original com marca de cancelamento",
  "xml": "XML ORIGINAL da NF-e",
  "pdf": "DANFE marcado em base64"
}
```

```yaml
success_http: 200
event_xml_returned: false
event_xml_persisted: emissoes_eventos.xml
event_type: CANCELAMENTO
rejection_http: 422
rejection_shape: {status: error, message: 'Erro ao cancelar: <xMotivo>'}
local_errors: {400: body/JSON, 401: token, 404: empresa/emissão, 422: campos/min15/modelo/dispatch, 500: interno}
```

#### CCeResponse

`POST /fiscal/nfe/carta` — contrato pretendido após corrigir `NFEventDispatchDefect`.

```json
{
  "chave": "string",
  "avisos": [],
  "protocolo": "string",
  "sequencia": 1,
  "link": "URL do DACCE",
  "xml": "XML protocolado da CC-e",
  "pdf": "DACCE em base64"
}
```

```yaml
pdf_failure_200: {avisos: ['Erro ao gerar PDF: ...'], link: '', pdf: '', xml: XML_evento}
rejection_http: 422
rejection_shape: {error: 'Erro ao processar CC-e', codigo: cStat, motivo: xMotivo}
event_type: CC
```

#### NFCeCancelResponse

```json
{
  "status": "success",
  "message": "Cancelamento homologado com sucesso!",
  "protocolo": "protocolo do evento",
  "xml": "XML protocolado do cancelamento"
}
```

```yaml
success_http: 200
event_type: CANCELAMENTO
event_link: ''
generated_pdf: false
rejection_http: 422
rejection_shape: {status: error, message: 'Erro ao cancelar: <xMotivo>'}
```

#### Tags e regras dos eventos

| Evento | `tpEvento` | Sequência | Detalhe | Tamanho técnico |
|---|---:|---|---|---|
| cancelamento NF-e/NFC-e | `110111` | normalmente `1` | `nProt`, `xJust` | `xJust`: 15..255 |
| CC-e NF-e | `110110` | `1..20` | `xCorrecao`, `xCondUso` | `xCorrecao`: 15..1000 |

Tags comuns: `envEvento@versao=1.00`, `idLote`, `evento@versao`, `infEvento@Id`, `cOrgao`, `tpAmb`, `CNPJ|CPF`, `chNFe`, `dhEvento`, `tpEvento`, `nSeqEvento`, `verEvento`, `detEvento` e `Signature`.

```yaml
NFE_cancel_legal:
  normal_deadline: 24h da autorização
  conditions: sem circulação, prestação ou vínculo à Duplicata Escritural
  extemporaneous: depende da UF
  future_rule: cláusula 12-A prevê hipótese 168h a partir de 2026-08-03; não implementada especificamente
NFCE_cancel_legal:
  national_deadline: 30min; UF pode reduzir
  conditions: sem saída/circulação
  Amazonas: 30min; extemporâneo até 90 dias apenas nas hipóteses/procedimento da Resolução 0018/2022
  API_scope: somente evento normal; sem liberação, DAR ou fluxo administrativo
CCe_legal:
  prohibited:
    - variáveis que determinam imposto/valor/quantidade
    - troca de remetente ou destinatário
    - data de emissão ou saída
    - campos de exportação informados na DU-E
    - inclusão/alteração de parcelas a prazo
  latest_event_must_consolidate_previous: true
  sefaz_protocol_does_not_validate_content: true
  NFCe_supported: false
```

Possíveis rejeições, sempre confirmar no MOC/schema e autorizador da UF: `215` schema; `217/580` documento inexistente/não autorizado; `220/501` prazo; `222` protocolo; `573` duplicidade; `574` autor; `577/578/579` data/hora; `594` sequência de CC-e.

### NFSeResponse

```json
{
  "chave": "chaveAcesso ou idDps",
  "protocolo": "idDps ou vazio",
  "avisos": [],
  "xml": "xmlNfse ou DPS assinada",
  "retorno": {}
}
```

Certificado:

```yaml
verifyCertificate:
  route: POST /fiscal/certicate/test
  source: JSON {certificado, senha}
  persistence: none
  flow: strip data URI -> strict base64 decode -> openCertificate -> x509 parse -> validate current validity -> parse subject.CN
  200: {empresa, cnpj, valido_de: DATE_ATOM, valido_ate: DATE_ATOM}
  400: JSON inválido ou não objeto
  422: body vazio, campos ausentes, base64 inválido, senha/PFX inválidos, parse inválido ou fora da validade
  500: erro interno sanitizado
testCertificate_saved:
  route: GET /fiscal/certicate/test/{cnpj}
  source: CompanyModel por CNPJ -> certificado e senha persistidos
  request_body: none
  persistence: none
  flow: normalize CNPJ -> find company -> read saved PFX -> openCertificate -> x509 parse -> parse subject.CN
  200: {emissao, dt_vencimento, nome, documento}
  404: empresa ausente
  422: senha/PFX inválidos ou parse X.509 inválido
  500: arquivo ausente/não legível ou erro interno sanitizado
  caveat: não verifica validade atual e não compara documento do subject.CN com o CNPJ da URL
common_limitations:
  - testes locais; não chamam a SEFAZ
  - não garantem aceitação da cadeia no TLS da SEFAZ
  - não consultam revogação nem status dos webservices
auth: se API_TOKEN configurado, Bearer ou X-Auth-Token obrigatório; falha retorna 401 antes do controller
public_path_typo: certicate é o path real por compatibilidade
```

## 7. Inventário de Controllers

### `CompanyController`

```yaml
find:
  calls: CompanyModel::find
  enrichment: instancia cada row por id e adiciona openssl_x509_parse como dados_certificado
  leak: mantém senha, nome do PFX e CSCs do SELECT raw
create:
  requires: certificado
  chain: uploadCertificado -> validateCertificate -> CompanyModel::create
update:
  route_id: passado ao construtor; linha existente é carregada
  certificate: opcional; se presente, upload+validate antes do update
```

### Controllers de catálogo

`CestController`, `CfopController`, `FormaPagamentoController`, `IbptController`, `NcmController`, `OrigemController` e `SituacaoTributariaController`: extraem `filter`/`limit`, chamam `Model::find`, respondem JSON 200; Exception capturada vira 500. `UnidadesController` difere: não usa try/catch, retorna 404 se resultado falsy, e o Model ignora limit.

### `EstadosController`

```yaml
findOnly: retorno interno; desempacota exatamente 1 row
find: resposta; desempacota exatamente 1 row; vazio fica []
findunique: primeiro row; ausente lança e retorna HTTP 401 texto
```

### `MunicipiosController`

```yaml
find: busca genérica
findunique: primeiro row; ausente => HTTP 401 texto
findByUf: EstadosController::findOnly(uf, limit 1) -> MunicipioModel::find(id_estado)
router_city_lookup: repete resolução de estado e procura nome exato + id_estado
```

### `EmissoesController`

```yaml
find: busca padrão em EmissoesModel
verifyCertificate:
  accepts: data URI/base64
  parser: UtilsController::openCertificate + openssl_x509_parse
  validity: validFrom <= now <= validTo
  subject: split CN at ':'
  persistence: none
testCertificate:
  accepts: CNPJ no path; usa PFX/senha cadastrados
  parser: UtilsController::getCertificado + openCertificate + openssl_x509_parse
  validity: apenas retorna as datas; não valida o instante atual
  subject: split CN at ':'; não compara documento ao CNPJ do path
  external_check: não chama SEFAZ
```

### `FiscalController`

Fachada de CRT. Resolve empresa/CRT e instancia o handler no construtor. Para cancelamento/CC-e reutiliza esse handler; como ele foi construído com o payload do evento, ocorre `NFEventDispatchDefect`.

### `BaseFiscalController` + `CRT1/2/3/4Controller`

O Base contém todo o pipeline comum de modelo 55. Cada filho implementa apenas `processarImpostosProduto` e seus geradores tributários. Os geradores produzem `stdClass` para métodos `Make::tag*`. O Base persiste eventos via método privado `salvarEvento`.

### `CupomFiscalController`

Pipeline duplicado e independente para modelo 65; não herda do Base e não despacha pelo CRT.

### `NotaServicoController`

Mapeia request para DPS/NFS-e Nacional, assina, envia e persiste sem PDF.

### `UtilsController`

| Método | Lógica / callers |
|---|---|
| `soNumero` | remove tudo que não seja dígito |
| `getCertifcado` | lê PFX salvo; typo é parte do nome |
| `openCertificate` | temp files + config OpenSSL + `openssl pkcs12 -legacy`, retry sem legacy; regex extrai primeiro cert/key |
| `readPfxForNFePHP` | cria objetos NFePHP a partir do helper anterior |
| `debugCertificate` | não roteado; valida concatenação de chaves e expõe diagnóstico |
| `testCertificate` | roteado; retorna datas/subject do PFX salvo |
| `uploadXml` / `uploadXmlPreview` | grava XML; retorna filename |
| `uploadPdf` / `uploadPdfPreview` | grava PDF; retorna path relativo |
| `gerarCpfValido` | CPF aleatório válido usado em fallback interno |
| `mod` | privado; dígito do CPF |
| `verificarOperacaoPorCFOP` | primeiro dígito 1/2/3 => tpNF 0; 5/6/7 => 1; default 0 |
| `validaCST` | pad para 3 e compara com lista de 2 dígitos; defeituoso |
| `validaCSOSN` | allowlist de CSOSN de 3 dígitos |

Split importante de certificado:

- validação/teste usam helper OpenSSL compatível com algoritmos legacy;
- emissão NF-e/NFC-e usa `Certificate::readPfx()` diretamente;
- um PFX pode passar no teste e falhar na emissão.

## 8. Inventário de Models

### `Connection`

```yaml
dsn: mysql:host={DB_SERVER};dbname={DB_NAME};charset=utf8
PDO_ERRMODE: EXCEPTION
PDO_EMULATE_PREPARES: false
failure: captura PDOException e retorna string de erro, não conexão/exception
closeConnection: internal connection = null
```

### `CompanyModel` → `empresa`

Campos de estado:

```text
id, cnpj, razao_social, nome_fantasia, telefone, email, cep, logradouro,
numero, bairro, cidade, uf, cnae, inscricao_estadual, inscricao_municipal,
atividade, logo, certificado, senha, csc, csc_id, datahora, tpamb,
serie_nfce, numero_nfce, serie_nfe, numero_nfe, serie_nfse, numero_nfse,
serie_nfce_homologacao, numero_nfce_homologacao,
serie_nfe_homologacao, numero_nfe_homologacao,
serie_nfse_homologacao, numero_nfse_homologacao,
csc_homologacao, csc_id_homologacao,
codigo_municipio, codigo_uf, situacao_tributaria, crt
```

```yaml
constructor(id?): conecta; se id truthy, SELECT por id e hidrata
getCurrentCompany: objeto interno com logo/segredos + dados_certificado
find(filter, limit): SELECT de igualdade dinâmica
create(data): INSERT fixo; normaliza CNPJ; hidrata e retorna objeto
update(data): atribui cada key diretamente à propriedade; UPDATE de todas as colunas
delete: DELETE por id; não roteado
getCertificate: lê PFX, abre e retorna parse OpenSSL
uploadCertificado: decodifica base64, grava e retorna filename
validateCertificate: false significa válido; string significa erro
getters_setters: par sem validação para cada campo
```

Contrato externo vigente: `CompanyController` usa allowlist do Model, remove segredos e nomes internos, converte `logo` em `logo_url` e gerencia rollback/substituição dos arquivos. `find()` interno continua usando `SELECT *`, mas a resposta passa pelo presenter do controller.

### `EmissoesModel` → `emissoes`

```yaml
logical_key: chave
fields: [chave, numero, serie, empresa, xml, pdf, tipo, protocolo, sequencia_cc]
constructor: SELECT por chave
find: filtros exatos; empresa é normalizada para dígitos
create: insere tudo, exceto sequencia_cc
update: atualiza todos os campos por chave
types_written: [NFE, NFCE, NFSE]
```

### `EmissoesEventosModel` → `emissoes_eventos`

```yaml
fields: [id, chave, tipo, protocolo, xml, link]
create: insert
update: update por chave
types_written: [CANCELAMENTO, CC]
constructor_defect: recebe chave/setChave, mas getById consulta id não definido
```

### Models de catálogo

Todos possuem lookup do construtor, serializador, `find` e getters/setters sem validação.

| Model | Tabela | Lookup | Campos |
|---|---|---|---|
| `CestModel` | `cest` | `cest_id` | `cest_id,ncm_id,descricao` |
| `CfopModel` | `cfop` | `id` | `id,descricao,aplicacao` |
| `FormaPagamentoModel` | `formas_pagtosefaz` | `codigo` | `codigo,descricao,cod_meio,meio` |
| `IbptModel` | `ibpt_nacional` | `codigo` | `codigo,nacional,importado` |
| `NcmModel` | `ncm` | `id` | `id,codigo,descricao` |
| `OrigemModel` | `origem` | `id` | `id,descricao` |
| `SituacaoTributariaModel` | `situacaotributaria` | `id` | `id,codigo,descricao,regime` |
| `UnidadesModel` | `unidades` | `id` | `id,nome,sigla` |
| `EstadosModel` | `estados` | `id` | `id,nome,uf,codigo_ibge` |
| `MunicipioModel` | `municipio` | `id` | `id,id_estado,nome,codigo_ibge` |

SQL genérico usa colunas dinâmicas interpoladas e valores parametrizados. `UnidadesModel` interpola colunas e valores diretamente e ignora `limit`.

## 9. Configuração fiscal e valores fixos

```yaml
NFE:
  versao: '4.00'
  schemes: PL_010_V1.30
  model: 55
  lot_id: integer 1 padded to 15 digits
  sync: 1
NFCE:
  versao: '4.00'
  schemes: PL_009_V4
  model: 65
common:
  tokenIBPT: AAAAAAA
  technical_responsible:
    CNPJ: '45730598000102'
    contact: Logic Tecnologia e Inovação
    email: contato.logictec@gmail.com
    phone: '92991225648'
    idCSRT: '01'
  default_autXML_CNPJ: '13937073000156'
```

## 10. Findings canônicos

| ID | Severidade | Local | Comportamento/impacto |
|---|---|---|---|
| SEC-001 | crítica | entrypoint/router | auth opcional/desabilitada com `API_TOKEN` vazio + CORS `*` |
| SEC-002 | crítica | erro NFC-e | CSC pode ir na resposta |
| NUM-001 | crítica | `NotaServicoController::atualizaNumero` | lê NFSe, grava incremento em NFe; NFSe repete |
| NUM-002 | crítica | todos os contadores | sem transação/lock; corrida duplica número |
| TAX-001 | crítica | `addICMSCombTag` | valores fiscais de combustível hardcoded |
| SQL-001 | alta | `UnidadesModel::find` | injeção SQL por key/value do filtro |
| SQL-002 | alta | demais `find` | nomes de coluna não allowlisted |
| DB-001 | alta | `Connection` | retorna string ao falhar conexão |
| DB-002 | alta | Models | capturam/imprimem PDO; JSON malformado ou falso 200 |
| TAX-002 | alta | CRT2/CRT3 | `valorIcms` não é somado a `totalIcms` |
| TAX-003 | alta | CRT3 | IPI/PIS/COFINS não alimentam consistentemente totais |
| TAX-004 | alta | NFC-e | vNF/vProd ignoram ajustes finais de frete/desconto/acréscimo |
| TAX-005 | alta | NFC-e | ICMSSN usado mesmo em CRT 2/3 |
| TAX-006 | alta | CRT1 IBS/CBS | `totalIBS` inclui produto e CBS, emitido como vIBS |
| NFE-001 | alta | Base | tpEmis 9 selecionado, mas envio continua online; contingência incompleta |
| NFCE-001 | alta | lote assíncrono | `processarLote` usa resposta local; merge usa `$this->response` antigo |
| EVT-001 | alta | `FiscalController`/Base | rotas cancelamento/CC-e NF-e instanciam handler com payload de evento e disparam validação de emissão completa; contrato correto fica bloqueado por 422 |
| EVT-002 | alta | cancelamento/CC-e | trata `128` externo como sucesso sem validar explicitamente `retEvento.infEvento.cStat`; pode persistir/retornar sucesso para evento rejeitado |
| OPS-001 | alta | repo | sem testes, migrations, idempotência, logs/reconciliação |
| TAX-007 | média | `validaCST` | pad 3 vs lista 2 dígitos; fallback constante |
| NFE-002 | média | total NF-e | desconto calculado é sobrescrito por vDesc=0 |
| NFE-003 | média | transporte | transportadora só se modFrete `=== 9`, provável inversão |
| NFSE-001 | média | sucesso NFS-e | exige inteiro 201; string `"201"` falha |
| CERT-001 | média | empresa | PFX salvo antes de validar; órfãos |
| CERT-002 | média | emissão | teste suporta legacy, emissão usa readPfx direto |
| MODEL-001 | média | evento constructor | chave recebida, query usa id unset |
| MODEL-002 | média | hydrators | não verificam fetch falso |
| API-001 | média | geral | texto/JSON/status inconsistentes; missing unique = 401 |
| API-002 | média | eventos | limites máximos de `xJust`/`xCorrecao`, chave de 44 dígitos e máximo de 20 CC-e não são pré-validados |
| API-003 | média | emissões | limite inacessível; body inteiro vira filtro |
| DEP-001 | média | composer | SDKs fiscais em dev-master |
| API-004 | baixa | rota | typo `certicate` faz parte do contrato |

## 11. Orientação de alteração segura

```yaml
test_without_external_effect:
  - unit tests de geradores de tags
  - schema validation de XML com fixtures
  - preview
  - gateways NFePHP/NFSeNacional mockados
  - concorrência de contadores
separate_fixtures:
  - produção vs homologação
  - NFE vs NFCE vs NFSE
  - CRT/CST/CSOSN
never_log:
  - PFX
  - senha PFX
  - private key
  - CSC
  - XML fiscal completo em logs irrestritos
```

Refactor recomendado: validação por DTO/schema; autenticação e serializer sem segredos; repositories com allowlist/transação; serviço atômico de numeração por empresa/modelo/ambiente/série; assemblers separados; calculadores tributários testáveis; gateways mockáveis; envelope de erro/log estruturado.

## 12. Manifesto de cobertura

```yaml
Routers:
  - app/Routers/Routers.php
Controllers:
  - app/Controllers/CestController.php
  - app/Controllers/CfopController.php
  - app/Controllers/CompanyController.php
  - app/Controllers/CupomFiscalController.php
  - app/Controllers/EmissoesController.php
  - app/Controllers/EstadosController.php
  - app/Controllers/FiscalController.php
  - app/Controllers/FormaPagamentoController.php
  - app/Controllers/IbptController.php
  - app/Controllers/MunicipiosController.php
  - app/Controllers/NcmController.php
  - app/Controllers/OrigemController.php
  - app/Controllers/SituacaoTributariaController.php
  - app/Controllers/UnidadesController.php
  - app/Controllers/UtilsController.php
  - app/Controllers/Fiscal/BaseFiscalController.php
  - app/Controllers/Fiscal/CRT1Controller.php
  - app/Controllers/Fiscal/CRT2Controller.php
  - app/Controllers/Fiscal/CRT3Controller.php
  - app/Controllers/Fiscal/CRT4Controller.php
  - app/Controllers/Fiscal/NotaServicoController.php
Models:
  - app/Models/CestModel.php
  - app/Models/CfopModel.php
  - app/Models/CompanyModel.php
  - app/Models/Connection.php
  - app/Models/EmissoesEventosModel.php
  - app/Models/EmissoesModel.php
  - app/Models/EstadosModel.php
  - app/Models/FormaPagamentoModel.php
  - app/Models/IbptModel.php
  - app/Models/MunicipioModel.php
  - app/Models/NcmModel.php
  - app/Models/OrigemModel.php
  - app/Models/SituacaoTributariaModel.php
  - app/Models/UnidadesModel.php
Supporting:
  - index.php
  - composer.json
  - composer.lock
  - .env.example
  - .htaccess
  - docs/nota-servico.md
  - docs/enviarDPS.md
validation:
  php_lint: passou em todo app/**/*.php
  live_database: indisponível; hostname DB não resolvido
  live_fiscal_calls: não executadas intencionalmente
```

## 13. Exemplos mínimos

Busca:

```http
POST /cfop/
Content-Type: application/json

{"filter":{"id":"5102"},"limit":1}
```

NF-e CRT 1, esqueleto prático:

```json
{
  "cnpj": "12345678000190",
  "cfop": "5102",
  "cliente": {
    "nome": "CLIENTE TESTE",
    "tipo_documento": "CPF",
    "documento": "12345678909",
    "endereco": {
      "logradouro": "RUA A",
      "numero": "1",
      "bairro": "CENTRO",
      "codigo_municipio": "1302603",
      "municipio": "MANAUS",
      "uf": "AM",
      "cep": "69000000"
    }
  },
  "produtos": [{
    "codigo": "1",
    "ean": "SEM GTIN",
    "descricao": "PRODUTO TESTE",
    "ncm": "84713012",
    "cfop": "5102",
    "unidade": "UN",
    "quantidade": 1,
    "valor": 10,
    "total": 10,
    "desconto": 0,
    "origem": 0,
    "cst_icms": "102"
  }],
  "pagamentos": [{"codigo":"01","valorpago":10}]
}
```

Cancelamento NF-e (rota atualmente afetada por `NFEventDispatchDefect`):

```json
{"cnpj":"12345678000190","chave":"CHAVE_DE_44_DIGITOS","justificativa":"Justificativa com pelo menos quinze caracteres"}
```

CC-e NF-e (rota atualmente afetada por `NFEventDispatchDefect`):

```json
{"cnpj":"12345678000190","chave":"CHAVE_DE_44_DIGITOS","carta":"Texto da correção permitido pela legislação aplicável"}
```

Cancelamento NFC-e:

```json
{"cnpj":"12345678000190","chave":"CHAVE_MODELO_65_COM_44_DIGITOS","justificativa":"Justificativa com pelo menos quinze caracteres"}
```

NFS-e:

```json
{
  "cnpj": "12345678000190",
  "total": 100,
  "cliente": {
    "tipo_documento": "CPF",
    "documento": "12345678909",
    "nome": "TOMADOR TESTE"
  },
  "servico": {
    "cTribNac": "010701",
    "xDescServ": "SERVICO DE TESTE",
    "cLocPrestacao": "1302603"
  }
}
```

## 14. Índice de métodos internos não triviais

Esta seção torna explícita a cobertura método a método. Getters/setters de Models apenas leem/escrevem a propriedade homônima e não validam valores.

### `FiscalController`

| Método | Função |
|---|---|
| `resolveCrtByCnpj` | normaliza CNPJ, procura empresa e retorna `crt` inteiro |
| `handlerFor` | resolve CRT e instancia `CRTxController($data)`; causa `EVT-001` quando `$data` é payload de evento |
| `createNfe`, `cancelNfe`, `gerarCC` | delegação ao mesmo handler já instanciado |

### `BaseFiscalController`

| Grupo | Métodos | Função |
|---|---|---|
| bootstrap | `bootstrapCompanyAndToolsByCnpj`, `initializeFromData`, `setConfig` | empresa, ambiente, contador, PFX, Tools, pagamentos, status e chave |
| identificação | `generateIdeData`, `montaChave`, `resolveIndIEDest` | IDE/chave e situação IE do destinatário |
| emitente | `generateDataCompany`, `generateDataAddress` | tags emitente/endereço cadastral |
| destinatário | `generateClientData`, `generateClientAddressData` | CPF/CNPJ, IE e endereço do cliente |
| produto | `generateProductData`, `generateProdutoInfoAdicional`, `generateImpostoData` | produto, info adicional e total tributário por item |
| combustível | `addCombustivelTag`, `addICMSCombTag` | ANP e ICMS hardcoded |
| totais | `generateIcmsTot`, `generateNFTotal` | ICMSTot e total IBS/CBS |
| adicionais | `generateIcmsInfo`, `generateReferencia`, `generateAutXMLData` | observação, chave referenciada e autorizado XML |
| transporte | `generateFreteData`, `generateTransportadoraData`, `generateVeiculoData`, `generateVolumeData`, `rateioFrete` | frete, transportador, veículo, volumes e rateio |
| pagamento | `generateFaturaData`, `generatePagamentoData` | troco e detalhe; cartão recebe integração não integrada/hardcoded |
| técnico | `generateResponsavelTecnico` | dados fixos do integrador |
| conectividade | `conexaoSefaz` | true somente em status 107 |
| retorno | `analisaRetorno`, `processarLote`, `loteProcessado`, `processarEmissao`, `processarPreview` | máquina de status, protocolo, DANFE e resposta |
| persistência | `atualizaNumero`, `salvaEmissao`, `salvarEvento` | contador, emissão e eventos |

### Controllers CRT

| Controller | Métodos | Função |
|---|---|---|
| CRT1 | `processarImpostosProduto`, `generateIcmssnData`, `generatePisDataSimple`, `generateConfinsDataSimple`, `generateIBSCBSData` | ICMSSN/PIS/COFINS/IBS/CBS |
| CRT2 | `processarImpostosProduto`, `generateICMSData`, `generateICMSDefault`, `generatePisDataSimple`, `generateConfinsDataSimple` | ICMS CST e contribuições zero |
| CRT3 | `processarImpostosProduto`, `generateICMSData`, `generateICMSDefault`, `generateIPIData`, `generatePisData`, `generatePisDataDefault`, `generateConfinsData`, `generateConfinsDataDefault` | regime normal completo |
| CRT4 | `processarImpostosProduto`, `generateIcmssnMei`, `generatePisDataMei`, `generateConfinsMei`, overrides `generateDataCompany`/`generateIcmsInfo` | MEI |

### `CupomFiscalController`

| Grupo | Métodos | Função |
|---|---|---|
| configuração | `setConfig`, `montaChave` | configuração NFePHP modelo 65 e chave |
| IDE/partes | `generateIdeData`, `generateDataCompany`, `generateDataAddress`, `generateClientData`, `generateClientAddressData` | tags principais |
| produto | `generateProductData`, `generateProdutoInfoAdicional`, `generateImpostoData`, `generateIcmssnData`, `resolveCSOSN` | item e tributação simplificada |
| combustível | `addCombustivelTag`, `addICMSCombTag` | ANP e imposto hardcoded |
| totais/pagamento | `generateIcmsTot`, `generateIcmsInfo`, `generateFreteData`, `generateFaturaData`, `generatePagamentoData` | fechamento do cupom |
| técnico | `generateReponsavelTecnicp` | dados fixos; nome contém typos no código |
| conectividade | `conexaoSefaz` | helper não chamado pelo fluxo de emissão |
| retorno | `analisaRetorno`, `processarLote`, `loteProcessado`, `processarEmissao` | status/protocolo/DANFCE |
| persistência | `atualizaNumero`, `salvaEmissao` | contador NFC-e e emissão |

### `NotaServicoController`

| Método | Função |
|---|---|
| `bootstrapCompany`, `getCertPath` | carrega empresa/ambiente/contador e resolve PFX absoluto |
| `buildDados` | constrói array completo da DPS |
| `buildPrestador`, `buildTomador`, `buildRegTrib` | partes da DPS e regra CRT → Simples |
| `extrairDescricaoServico` | une descrições de produtos por `; ` |
| `gerarIdDPS` | identificador DPS com padding fixo |
| `atualizaNumero` | contém NUM-001 |
| `salvaEmissao` | grava `NFSE`, XML e PDF vazio |

### Models especiais

| Model/método | Função |
|---|---|
| `UnidadesModel::findById` | hidrata por id; chamado no construtor |
| `UnidadesModel::find` | SELECT com interpolação insegura |
| todos os `getById` privados | SELECT/hidratação pela chave descrita na seção 8 |
| todos os `getCurrent`/`current` | serialização das propriedades do Model |
| todos os `find` | busca por igualdade; diferenças documentadas na seção 8 |
| `EmissoesModel::create/update` | persistência de documentos/sequência CC-e |
| `EmissoesEventosModel::create/update` | persistência de eventos |
| `CompanyModel::create/update/delete` | CRUD da empresa |

Fim do contexto canônico para IA.
