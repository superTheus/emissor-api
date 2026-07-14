# Emissor API

## Sobre

Uma API para emissão de notas fiscais eletrônicas (NF-e), notas fiscais de consumidor eletrônicas (NFC-e) e Nota fiscal de Serviço (NFS-e) de forma simples e rápida. 

Além disso, pode emitir outros documentos fiscais como carta de correção, e relizar diversas operações como cancelamento, inutilização, consulta de status, entre outros.

## Funcionalidades

- Emissão de NF-e, NFC-e e NFS-e
- Emissão de carta de correção
- Cancelamento de notas fiscais
- Inutilização de notas fiscais
- Consulta de status de notas fiscais

## Banco de Dados

host: localhost
database: emissor
user: root
password: 

## Docs

Em [docs](c:/devilbox/data/www/projetos/htdocs/emissor-api/docs/) tem documentações e registros que podem ser úteis para o desenvolvimento da API.

## Tecnologias utilizadas

- PHP 8.2
- Bramus/Router
- MySQL
- [nfephp-org/sped-nfe](https://github.com/nfephp-org/sped-nfe)


## Links Uteis

Use as fontes abaixo para entender as regras fiscais brasileiras e a emissão de NF-e modelo 55 e NFC-e modelo 65:

Portal Nacional da NF-e:
https://www.nfe.fazenda.gov.br/

Manual de Orientação do Contribuinte — MOC:
https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=ndIjl+iEFdE%3D

Notas Técnicas da NF-e/NFC-e:
https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=04BIflQt1aY%3D

Schemas XML/XSD:
https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=BMPFMBoln3w%3D

Webservices de produção e homologação:
https://www.nfe.fazenda.gov.br/portal/webServices.aspx?tipoConteudo=OUC%2FYVNWZfo%3D

Tabelas oficiais da NF-e, NCM, CST e cClassTrib:
https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=%2FNJarYc9nus%3D

Perguntas frequentes da NF-e:
https://www.nfe.fazenda.gov.br/portal/perguntasFrequentes.aspx?tipoConteudo=PN6e+JQMTxs%3D

Ajuste SINIEF 07/2005 — NF-e:
https://www.confaz.fazenda.gov.br/legislacao/ajustes/2005/AJ007_05

Ajuste SINIEF 19/2016 — NFC-e:
https://www.confaz.fazenda.gov.br/legislacao/ajustes/2016/AJ_019_16

Portal de legislação do CONFAZ:
https://www.confaz.fazenda.gov.br/

Ajuste SINIEF 03/2010 — CSOSN:
https://www.confaz.fazenda.gov.br/legislacao/ajustes/2010/AJ_003_10

Convênio ICMS 142/2018 — ICMS-ST e CEST:
https://www.confaz.fazenda.gov.br/legislacao/convenios/2018/CV142_18

Portal Nacional da Substituição Tributária:
https://www.confaz.fazenda.gov.br/legislacao/substituicao-tributaria

Classificação Fiscal e NCM:
https://www.gov.br/receitafederal/pt-br/assuntos/aduana-e-comercio-exterior/classificacao-fiscal-de-mercadorias

Tabela TIPI:
https://www.gov.br/receitafederal/pt-br/acesso-a-informacao/legislacao/tipi-tabela-de-incidencia-do-imposto-sobre-produtos-industrializados

Nota Técnica 2025.002 — IBS/CBS:
https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=04BIflQt1aY%3D

Informes Técnicos e tabelas IBS/CBS:
https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=hXzemuyNHW4%3D

Orientações da Reforma Tributária para 2026:
https://www.gov.br/receitafederal/pt-br/acesso-a-informacao/acoes-e-programas/programas-e-atividades/reforma-tributaria-do-consumo/orientacoes-2026

Lei Complementar 214/2025:
https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp214.htm

Lei Complementar 227/2026:
https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp227.htm

Documentação técnica da NFC-e — SEFAZ Amazonas:
https://portalnfce.sefaz.am.gov.br/desenvolvedor/documentacao-tecnica/

Serviços da SEFAZ Amazonas:
https://www.sefaz.am.gov.br/portfolio-servicos/todos

Legislação tributária do Amazonas:
https://sistemas.sefaz.am.gov.br/silt/

RICMS do Amazonas:
https://sistemas.sefaz.am.gov.br/get/Normas.do?metodo=viewDoc&uuidDoc=cc3888c0-e1b9-4433-b513-3c0f29cc625a

Documentação da NFS-e Nacional:
https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual

APIs da NFS-e Nacional:
https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao

NFePHP — emissão e comunicação com a SEFAZ:
https://github.com/nfephp-org/sped-nfe

NFePHP — geração de DANFE e DANFCE:
https://github.com/nfephp-org/sped-da

Ao responder:

- Utilize prioritariamente fontes oficiais.
- Verifique sempre a versão mais recente das Notas Técnicas e schemas.
- Não invente CFOP, NCM, CST, CSOSN, CEST, alíquotas ou cClassTrib.
- Diferencie regras tributárias de regras técnicas do XML.
- Considere UF, CRT, regime tributário, operação, destinatário e finalidade.
- Informe a fonte e a versão utilizada em cada resposta.
- Apresente as tags XML, cálculos, totalizadores e possíveis rejeições.
- Quando possível, forneça exemplos compatíveis com NFePHP.