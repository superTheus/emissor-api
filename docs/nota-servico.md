# NFS-e — Nota Fiscal de Serviços Eletrônica

## O que foi implementado

Controller `NotaServicoController` (`app/Controllers/Fiscal/NotaServicoController.php`) para emissão de NFS-e pelo **Sistema Nacional NFS-e** (SEFIN Nacional).

O controller:
- Reutiliza o mesmo payload da emissão de NFe — não é necessário mudar o body da requisição.
- Carrega automaticamente os dados da empresa (certificado, CNPJ, município, regime tributário) a partir do CNPJ informado.
- Monta a DPS (Declaração de Prestação de Serviços), assina digitalmente e envia para a API Nacional.
- Persiste a emissão na tabela `emissoes` com `tipo = 'NFSE'`.
- Utiliza o campo `serie_nfe` / `numero_nfe` da empresa como contador de série/número da NFS-e (compartilhado enquanto não houver campo dedicado no banco).

---

## Rota

| Método | Endpoint        | Descrição       |
|--------|-----------------|-----------------|
| POST   | `/fiscal/nfse`  | Emitir NFS-e    |

---

## Body da requisição

O body é **idêntico** ao da emissão de NFe, com a adição opcional da chave `servico` para informações específicas da NFS-e.

### Campos obrigatórios (herdados da NFe)

| Campo              | Tipo   | Descrição                                      |
|--------------------|--------|------------------------------------------------|
| `cnpj`             | string | CNPJ da empresa emissora (com ou sem máscara)  |
| `cliente`          | object | Dados do tomador do serviço                    |
| `cliente.nome`     | string | Razão social / nome do tomador                 |
| `cliente.tipo_documento` | string | `CPF` ou `CNPJ`                         |
| `cliente.documento`| string | CPF ou CNPJ do tomador                         |
| `cliente.endereco` | object | Endereço do tomador                            |
| `total`            | float  | Valor total do serviço                         |

### Endereço do tomador (`cliente.endereco`)

| Campo               | Tipo   | Descrição                        |
|---------------------|--------|----------------------------------|
| `codigo_municipio`  | string | Código IBGE do município (7 dígitos) |
| `cep`               | string | CEP                              |
| `logradouro`        | string | Logradouro                       |
| `numero`            | string | Número (use `"SN"` se sem número)|
| `bairro`            | string | Bairro                           |

### Chave `servico` — específica NFS-e (opcional)

Se não informada, os valores abaixo serão usados como padrão.

| Campo              | Tipo   | Padrão           | Descrição                                                                 |
|--------------------|--------|------------------|---------------------------------------------------------------------------|
| `cTribNac`         | string | `010701`         | Código de tributação nacional do serviço                                  |
| `cLocPrestacao`    | string | município da empresa | Código IBGE do município onde o serviço foi prestado                 |
| `xDescServ`        | string | descrição dos produtos | Descrição do serviço                                                |
| `xInfComp`         | string | `observacao`     | Informações complementares                                                |
| `cIntContrib`      | string | `""`             | Código interno (ID da venda no sistema)                                   |
| `dCompet`          | string | data atual       | Data de competência (`Y-m-d`)                                             |
| `serie`            | int    | série NFe da empresa | Série da DPS                                                          |
| `numero`           | int    | número NFe da empresa | Número da DPS                                                        |
| `tribISSQN`        | int    | `1`              | Tributação ISSQN (1 = tributado)                                          |
| `tpRetISSQN`       | int    | `1`              | Tipo de retenção ISSQN (1 = próprio município)                            |
| `pTotTribSN`       | float  | `0.00`           | Percentual total tributos Simples Nacional                                |

### Chave `fiscal` — sobrescrever regime tributário (opcional)

Útil apenas quando o regime tributário da empresa no banco difere do desejado para a nota.

| Campo          | Tipo | Descrição                                                                                         |
|----------------|------|---------------------------------------------------------------------------------------------------|
| `opSimpNac`    | int  | 1 = Não Optante; 2 = MEI; 3 = ME/EPP                                                             |
| `regApTribSN`  | int  | 1 = SN; 2 = SN + ISSQN pela legislação municipal; 3 = Federal e municipal por legislação própria |
| `regEspTrib`   | int  | 0 = Nenhum; 1–6 = regimes especiais                                                              |

---

## Resposta de sucesso (`HTTP 200`)

```json
{
  "chave": "DPS5208707114...",
  "protocolo": "DPS5208707114...",
  "avisos": [],
  "xml": "<CompNfse>...</CompNfse>",
  "retorno": {
    "codigo": 201,
    "mensagem": "DPS registrada com sucesso.",
    "tipoAmbiente": "Homologação",
    "dataHoraProcessamento": "2026-04-21T10:00:00-03:00",
    "idDps": "DPS5208707114...",
    "chaveAcesso": "DPS5208707114...",
    "xmlNfse": "<CompNfse>...</CompNfse>",
    "alertas": []
  }
}
```

## Resposta de erro (`HTTP 403`)

```json
{
  "error": "Erro ao emitir NFS-e",
  "retorno": {
    "codigo": "422",
    "mensagem": "Erro ao enviar DPS.",
    "bodyOriginal": "..."
  }
}
```

---

## Exemplos de uso

### Exemplo mínimo (sem chave `servico`)

```json
POST /fiscal/nfse
Content-Type: application/json

{
  "cnpj": "12.345.678/0001-00",
  "total": 350.00,
  "observacao": "Prestação de serviço de consultoria - Abril/2026",
  "cliente": {
    "nome": "Empresa Contratante LTDA",
    "tipo_documento": "CNPJ",
    "documento": "98.765.432/0001-10",
    "email": "financeiro@contratante.com.br",
    "telefone": "6299887766",
    "endereco": {
      "codigo_municipio": "5208707",
      "cep": "74275-050",
      "logradouro": "Rua C-136",
      "numero": "100",
      "bairro": "Jardim América",
      "municipio": "Goiânia",
      "uf": "GO"
    }
  },
  "produtos": [
    {
      "descricao": "CONSULTORIA EM TECNOLOGIA DA INFORMAÇÃO - ABRIL/2026"
    }
  ]
}
```

### Exemplo completo (com chave `servico`)

```json
POST /fiscal/nfse
Content-Type: application/json

{
  "cnpj": "12.345.678/0001-00",
  "total": 120.00,
  "observacao": "Suporte técnico mensal",
  "cliente": {
    "nome": "João da Silva",
    "tipo_documento": "CPF",
    "documento": "123.456.789-09",
    "email": "joao@email.com",
    "telefone": "6299999999",
    "endereco": {
      "codigo_municipio": "5208707",
      "cep": "74000-000",
      "logradouro": "Av. Anhanguera",
      "numero": "1000",
      "bairro": "Centro",
      "municipio": "Goiânia",
      "uf": "GO"
    }
  },
  "produtos": [
    {
      "descricao": "SUPORTE E MANUTENÇÃO - MENSALIDADE"
    }
  ],
  "servico": {
    "cTribNac": "010701",
    "cLocPrestacao": "5208707",
    "xDescServ": "SUPORTE E MANUTENCAO - MENSALIDADE",
    "xInfComp": "{\"infAdicLT\":\"5208707\",\"infAdicES\":\"N\"}",
    "cIntContrib": "ID_VENDA_123",
    "tribISSQN": 1,
    "tpRetISSQN": 1,
    "pTotTribSN": 0.00
  }
}
```

### Exemplo com sobrescrita do regime tributário

```json
{
  "cnpj": "12.345.678/0001-00",
  "total": 500.00,
  "...": "...",
  "fiscal": {
    "opSimpNac": 3,
    "regApTribSN": 1,
    "regEspTrib": 0
  }
}
```

---

## Mapeamento de campos NFe → NFS-e

| Campo NFe                          | Campo NFS-e                         | Observação                                         |
|------------------------------------|-------------------------------------|----------------------------------------------------|
| `cnpj`                             | `prestador.cnpj`                    | Buscado no cadastro da empresa                     |
| `cliente.documento` (CNPJ)         | `tomador.cnpj`                      |                                                    |
| `cliente.documento` (CPF)          | `tomador.cpf`                       |                                                    |
| `cliente.nome`                     | `tomador.xNome`                     |                                                    |
| `cliente.telefone`                 | `tomador.fone`                      | Somente números                                    |
| `cliente.email`                    | `tomador.email`                     |                                                    |
| `cliente.endereco.codigo_municipio`| `tomador.endereco.cMun`             |                                                    |
| `cliente.endereco.cep`             | `tomador.endereco.CEP`              |                                                    |
| `cliente.endereco.logradouro`      | `tomador.endereco.xLgr`             |                                                    |
| `cliente.endereco.numero`          | `tomador.endereco.nro`              |                                                    |
| `cliente.endereco.bairro`          | `tomador.endereco.xBairro`          |                                                    |
| `total`                            | `valores.vServ`                     |                                                    |
| `observacao`                       | `servico.xInfComp`                  | Quando `servico.xInfComp` não informado            |
| `produtos[].descricao`             | `servico.xDescServ`                 | Concatenados por `"; "` quando `servico.xDescServ` não informado |
| empresa.codigo_municipio           | `prestador.cLocEmi` / `cLocEmi`     | Buscado no cadastro                                |
| empresa.inscricao_municipal        | `prestador.im`                      | Buscado no cadastro                                |
| empresa.crt                        | `prestador.regTrib.opSimpNac`       | Mapeado automaticamente (CRT 1/4→3, CRT 2→3, CRT 3→1) |

---

## Observações importantes

- **Ambiente**: controlado pelo campo `tpamb` no cadastro da empresa (1 = Produção, 2 = Homologação).
- **Certificado**: lido diretamente de `app/storage/certificados/{nome_do_arquivo}` cadastrado na empresa.
- **Série e Número**: compartilhados com a NFe enquanto não houver coluna dedicada no banco. É possível sobrescrever via `servico.serie` e `servico.numero` no payload.
- **Persistência**: a NFS-e é salva na tabela `emissoes` com `tipo = 'NFSE'`, mesma estrutura das NFe/NFC-e.
- **cTribNac**: código de tributação nacional. Consulte o e-CAC ou a prefeitura municipal para obter o código correto para cada tipo de serviço. Exemplo: `010701` = Suporte técnico, manutenção e outros serviços em TI.
