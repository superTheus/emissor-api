<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\CompanyController;
use App\Controllers\CupomFiscalController;
use App\Controllers\EmissoesEventosController;
use App\Controllers\Fiscal\CRT1Controller;
use App\Controllers\Fiscal\NotaServicoController;
use App\Controllers\UtilsController;
use App\Http\HttpException;
use App\Models\Concerns\FindsByFilters;
use App\Services\CompanyLogoStorage;

final class TestFilterModel
{
    use FindsByFilters;

    private PDO $conn;
    private string $table = 'items';

    public function __construct(PDO $connection)
    {
        $this->conn = $connection;
    }

    public function search($filters = [], $limit = null): array
    {
        return $this->findByFilters($filters, $limit);
    }

    protected function filterableColumns(): array
    {
        return ['id', 'name'];
    }
}

final class TestOrderedFilterModel
{
    use FindsByFilters;

    private PDO $conn;
    private string $table = 'items';

    public function __construct(PDO $connection)
    {
        $this->conn = $connection;
    }

    public function search($filters = [], $limit = null): array
    {
        return $this->findByFilters($filters, $limit);
    }

    protected function filterableColumns(): array
    {
        return ['id', 'name'];
    }

    protected function defaultOrderBy(): ?array
    {
        return ['id', 'DESC'];
    }
}

$tests = [];

$test = static function (string $name, callable $callback) use (&$tests): void {
    $tests[] = [$name, $callback];
};

$assertSame = static function ($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message ?: sprintf(
            'Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
};

$assertTrue = static function ($actual, string $message = '') use ($assertSame): void {
    $assertSame(true, $actual, $message);
};

$test('normaliza documentos', static function () use ($assertSame): void {
    $assertSame('12345678000190', UtilsController::soNumero('12.345.678/0001-90'));
});

$test('normaliza PEM com quebra de linha final para comunicação SOAP', static function () use ($assertSame): void {
    $method = new ReflectionMethod(UtilsController::class, 'normalizePem');
    $method->setAccessible(true);
    $pem = "  -----BEGIN PRIVATE KEY-----\r\nABC\r\n-----END PRIVATE KEY-----  ";

    $assertSame(
        "-----BEGIN PRIVATE KEY-----\nABC\n-----END PRIVATE KEY-----\n",
        $method->invoke(null, $pem)
    );
});

$test('preserva a cadeia intermediária do PFX usado pela NFePHP', static function () use ($assertSame): void {
    $options = [
        'digest_alg' => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    $caKey = openssl_pkey_new($options);
    $caCsr = openssl_csr_new(['commonName' => 'CA TESTE'], $caKey, $options);
    $caCertificate = openssl_csr_sign($caCsr, null, $caKey, 30, $options);
    openssl_x509_export($caCertificate, $caPem);

    $leafKey = openssl_pkey_new($options);
    $leafCsr = openssl_csr_new(['commonName' => 'EMPRESA TESTE:12345678000190'], $leafKey, $options);
    $leafCertificate = openssl_csr_sign($leafCsr, $caCertificate, $caKey, 30, $options);
    openssl_pkcs12_export(
        $leafCertificate,
        $pfx,
        $leafKey,
        'senha-teste',
        ['extracerts' => [$caPem]]
    );

    $opened = UtilsController::openCertificate($pfx, 'senha-teste');
    $assertSame(1, count($opened['extracerts']));

    $certificate = UtilsController::readPfxForNFePHP($pfx, 'senha-teste');
    $assertSame(1, count($certificate->chainKeys->listChain()));
});

$test('filtros SQL usam allowlist e parâmetros', static function () use ($assertSame): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec("INSERT INTO items (name) VALUES ('normal'), ('outro')");
    $model = new TestFilterModel($pdo);

    $assertSame('normal', $model->search(['name' => 'normal'], 1)[0]['name']);
    $assertSame([], $model->search(['name' => "normal' OR 1=1 --"]));

    try {
        $model->search(['name OR 1=1' => 'x']);
        throw new RuntimeException('Filtro inválido foi aceito.');
    } catch (InvalidArgumentException $exception) {
        $assertSame(true, str_contains($exception->getMessage(), 'não permitido'));
    }

    $assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
});

$test('consulta ordenada aplica limite depois da ordenação', static function () use ($assertSame): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec("INSERT INTO items (name) VALUES ('primeiro'), ('ultimo')");
    $model = new TestOrderedFilterModel($pdo);

    $result = $model->search([], 1);

    $assertSame(2, (int) $result[0]['id']);
    $assertSame('ultimo', $result[0]['name']);
});

$test('consulta eventos encaminha filtros e apresenta link público', static function () use ($assertSame): void {
    $_ENV['URL_BASE'] = 'https://api.example.test/';
    $receivedFilter = null;
    $receivedLimit = null;
    $controller = new EmissoesEventosController(
        static function ($filter, $limit) use (&$receivedFilter, &$receivedLimit): array {
            $receivedFilter = $filter;
            $receivedLimit = $limit;

            return [[
                'id' => 7,
                'chave' => '13260712345678000190550010000001231123456780',
                'tipo' => 'CC',
                'protocolo' => '113260000000003',
                'xml' => '<procEventoNFe/>',
                'link' => 'app/storage/fiscal/pdf/pdf_cce_chave_seq1.pdf',
            ]];
        }
    );

    set_error_handler(static fn(): bool => true, E_WARNING);
    ob_start();
    try {
        $controller->find([
            'filter' => ['chave' => '13260712345678000190550010000001231123456780', 'tipo' => 'CC'],
            'limit' => 10,
        ]);
        $response = json_decode((string) ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        ob_end_clean();
        restore_error_handler();
    }

    $assertSame(
        ['chave' => '13260712345678000190550010000001231123456780', 'tipo' => 'CC'],
        $receivedFilter
    );
    $assertSame(10, $receivedLimit);
    $assertSame(
        'https://api.example.test/app/storage/fiscal/pdf/pdf_cce_chave_seq1.pdf',
        $response[0]['link']
    );
    $assertSame('<procEventoNFe/>', $response[0]['xml']);
});

$test('valida CST com dois dígitos', static function () use ($assertTrue, $assertSame): void {
    $assertTrue(UtilsController::validaCST('01'));
    $assertTrue(UtilsController::validaCST(6));
    $assertSame(false, UtilsController::validaCST('99'));
});

$test('valida CSOSN', static function () use ($assertTrue, $assertSame): void {
    $assertTrue(UtilsController::validaCSOSN('102'));
    $assertSame(false, UtilsController::validaCSOSN('999'));
});

$test('resolve tipo de operação pelo CFOP', static function () use ($assertSame): void {
    $assertSame(0, UtilsController::verificarOperacaoPorCFOP('1102'));
    $assertSame(1, UtilsController::verificarOperacaoPorCFOP('5102'));
});

$test('monta URL pública sem barras duplicadas', static function () use ($assertSame): void {
    $_ENV['URL_BASE'] = 'https://api.example.test/';
    $assertSame(
        'https://api.example.test/app/storage/file.pdf',
        UtilsController::publicUrl('/app/storage/file.pdf')
    );
});

$test('gera identificador DPS determinístico', static function () use ($assertSame): void {
    $controller = (new ReflectionClass(NotaServicoController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(NotaServicoController::class, 'gerarIdDPS');
    $method->setAccessible(true);
    $id = $method->invoke($controller, '1302603', '12345678000190', 1, 15);

    $assertSame('DPS130260311234567800019000001000000000000015', $id);
});

$test('rateia frete proporcionalmente', static function () use ($assertSame): void {
    $controller = (new ReflectionClass(CRT1Controller::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(CRT1Controller::class, 'rateioFrete');
    $method->setAccessible(true);

    $assertSame(5.0, $method->invoke($controller, 50, 100, 10));
    $assertSame(0, $method->invoke($controller, 50, 0, 10));
});

$test('total IBS não inclui produto nem CBS', static function () use ($assertSame): void {
    $controller = (new ReflectionClass(CRT1Controller::class))->newInstanceWithoutConstructor();
    foreach ([
        'baseCalculo' => 100.0,
        'aliquotaIbsEstadual' => 1.0,
        'aliquotaIbsMunicipal' => 2.0,
        'aliquotaCbs' => 3.0,
    ] as $propertyName => $value) {
        $property = new ReflectionProperty(CRT1Controller::class, $propertyName);
        $property->setAccessible(true);
        $property->setValue($controller, $value);
    }

    $method = new ReflectionMethod(CRT1Controller::class, 'generateIBSCBSData');
    $method->setAccessible(true);
    $method->invoke($controller, ['total' => 100], 1);

    $totalIbs = new ReflectionProperty(CRT1Controller::class, 'totalIBS');
    $totalIbs->setAccessible(true);
    $assertSame(3.0, $totalIbs->getValue($controller));
});

$test('combustível exige tributação explícita', static function () use ($assertSame): void {
    $controller = (new ReflectionClass(CRT1Controller::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(CRT1Controller::class, 'addICMSCombTag');
    $method->setAccessible(true);

    try {
        $method->invoke($controller, ['origem' => 0], 0);
        throw new RuntimeException('A validação de combustível não foi executada.');
    } catch (ReflectionException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        $cause = $exception instanceof ReflectionException ? $exception : $exception->getPrevious();
        $message = $cause?->getMessage() ?? $exception->getMessage();
        $assertSame(true, str_contains($message, 'icms_combustivel'));
    }

    $result = $method->invoke($controller, [
        'origem' => 0,
        'icms_combustivel' => [
            'CST' => '61',
            'qBCMonoRet' => '10.0000',
            'adRemICMSRet' => '1.2200',
            'vICMSMonoRet' => '12.20',
        ],
    ], 0);
    $assertSame('12.20', $result->vICMSMonoRet);
});

$test('não expõe segredos da empresa', static function () use ($assertSame): void {
    $controller = new CompanyController();
    $method = new ReflectionMethod(CompanyController::class, 'sanitizeCompany');
    $method->setAccessible(true);
    $result = $method->invoke($controller, [
        'id' => 1,
        'cnpj' => '12345678000190',
        'senha' => 'secret',
        'certificado' => 'certificate.pfx',
        'csc' => 'secret-csc',
        'csc_id' => '1',
        'csc_homologacao' => 'secret-csc-test',
        'csc_id_homologacao' => '2',
    ]);

    $assertSame(['id' => 1, 'cnpj' => '12345678000190'], $result);
});

$test('valida, salva e remove logo da empresa', static function () use ($assertSame, $assertTrue): void {
    $directory = sys_get_temp_dir() . '/emissor-logo-' . bin2hex(random_bytes(8));
    $storage = new CompanyLogoStorage($directory);
    $fileName = null;

    try {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $fileName = $storage->store('data:image/png;base64,' . $png);

        $assertTrue(str_starts_with($fileName, 'logo_'));
        $assertTrue(str_ends_with($fileName, '.png'));
        $assertTrue(is_file($directory . '/' . $fileName));

        try {
            $storage->store('data:image/jpeg;base64,' . $png);
            throw new RuntimeException('Data URL com MIME divergente foi aceita.');
        } catch (InvalidArgumentException $exception) {
            $assertTrue(str_contains($exception->getMessage(), 'não corresponde'));
        }

        try {
            $storage->store(base64_encode('<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
            throw new RuntimeException('SVG foi aceito como logo.');
        } catch (InvalidArgumentException $exception) {
            $assertTrue(str_contains($exception->getMessage(), 'Formato'));
        }
    } finally {
        $storage->remove($fileName);
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

$test('apresenta URL pública da logo sem expor nome interno', static function () use ($assertSame): void {
    $_ENV['URL_BASE'] = 'https://api.example.test/';
    $controller = new CompanyController();
    $method = new ReflectionMethod(CompanyController::class, 'presentCompany');
    $method->setAccessible(true);
    $result = $method->invoke($controller, [
        'id' => 1,
        'logo' => 'logo_example.png',
        'senha' => 'secret',
        'dados_certificado' => [
            'subject' => ['CN' => 'EMPRESA TESTE:12345678000190'],
            'validFrom_time_t' => 1767225600,
            'validTo_time_t' => 1798761600,
        ],
    ]);

    $assertSame([
        'id' => 1,
        'dados_certificado' => [
            'titular' => 'EMPRESA TESTE:12345678000190',
            'valido_de' => date(DATE_ATOM, 1767225600),
            'valido_ate' => date(DATE_ATOM, 1798761600),
        ],
        'logo_url' => 'https://api.example.test/app/storage/logos/logo_example.png',
    ], $result);
});

$test('validação da NF-e retorna todos os campos inválidos em error_tags', static function () use ($assertSame, $assertTrue): void {
    $controller = (new ReflectionClass(CRT1Controller::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(CRT1Controller::class, 'validateEmissionData');
    $method->setAccessible(true);

    try {
        $method->invoke($controller, [
            'cnpj' => '123',
            'cfop' => '51',
            'cliente' => 'inválido',
            'produtos' => [['quantidade' => 0, 'valor' => -1]],
            'total' => 'inválido',
            'pagamentos' => [['valorpago' => -1]],
        ]);
        throw new RuntimeException('Payload inválido foi aceito.');
    } catch (HttpException $exception) {
        $assertSame(422, $exception->status());
        $tags = $exception->context()['error_tags'] ?? [];
        $assertTrue(count($tags) > 5, 'A validação deveria acumular os erros do payload.');
        $assertTrue((bool) array_filter($tags, static fn($tag) => str_contains($tag, 'cliente: deve ser um objeto')));
        $assertTrue((bool) array_filter($tags, static fn($tag) => str_contains($tag, 'pagamentos[0].codigo')));
    }
});

$test('retorno de conserto monta ide, referência e pagamento 90 sem troco', static function () use ($assertSame, $assertTrue): void {
    $controller = new class extends CRT1Controller {
        public function __construct()
        {
        }

        public function configure(array $data, array $payments): void
        {
            $this->data = $data;
            $this->pagamentos = $payments;
            $this->serie = 1;
            $this->numero = 123;
            $this->dataEmissao = '2026-07-31T12:00:00-04:00';
            $this->modo_emissao = 1;
            $this->currentChave = '29260701272713000177550010000001231234567890';
            $this->company = new class {
                public function getCodigo_uf(): string { return '29'; }
                public function getCodigo_municipio(): string { return '2903201'; }
                public function getUf(): string { return 'BA'; }
            };
        }

        public function validateContract(array $data): void
        {
            $this->validateEmissionData($data);
        }

        public function ide(array $data): object
        {
            return $this->generateIdeData($data);
        }

        public function references(array $data): array
        {
            return $this->referencedNfeKeys($data);
        }

        public function reference(string $key): object
        {
            return $this->generateReferencia($key);
        }

        public function payment(array $payment): object
        {
            return $this->generatePagamentoData($payment);
        }

        public function paymentGroup(): object
        {
            return $this->generateFaturaData();
        }
    };

    $key = '29260510962697000720550020000500781262109988';
    $formattedKey = '2926 0510 9626 9700 0720 5500 2000 0500 7812 6210 9988';
    $payload = [
        'cnpj' => '01272713000177',
        'cfop' => '5916',
        'operacao' => 'Retorno de mercadoria ou bem recebido para conserto ou reparo',
        'finalidade' => 1,
        'indicador_presenca' => 0,
        'consumidor_final' => 'S',
        'nota_referencia' => $formattedKey,
        'notas_referenciadas' => [$key, $formattedKey],
        'cliente' => [
            'nome' => 'AMAGGI LOUIS DREYFUS ZEN NOH GRAOS S.A',
            'tipo_documento' => 'CNPJ',
            'documento' => '10962697000720',
            'endereco' => [
                'logradouro' => 'RODOVIA BA 225 KM 69 COACERAL',
                'numero' => 'S/N',
                'bairro' => 'ZONA RURAL',
                'codigo_municipio' => '2911105',
                'municipio' => 'Formosa do Rio Preto',
                'uf' => 'BA',
                'cep' => '47990000',
            ],
        ],
        'produtos' => [[
            'codigo' => '98',
            'ean' => null,
            'descricao' => 'PA CARREGADEIRA CASE W20E',
            'ncm' => '84295199',
            'cfop' => '5916',
            'unidade' => 'UN',
            'quantidade' => 1,
            'valor' => 298000,
            'total' => 298000,
            'origem' => 0,
        ]],
        'total' => 298000,
        'total_pago' => 0,
        'troco' => 0,
        'pagamentos' => [['codigo' => '90', 'valorpago' => 0]],
    ];

    $controller->validateContract($payload);
    $controller->configure($payload, [['indPag' => 2, 'tPag' => '90', 'valorpago' => 0]]);

    $ide = $controller->ide($payload);
    $references = $controller->references($payload);
    $payment = $controller->payment(['indPag' => 2, 'tPag' => '90', 'valorpago' => 0]);
    $paymentGroup = $controller->paymentGroup();

    $assertSame(1, $ide->finNFe);
    $assertSame(0, $ide->indPres);
    $assertSame([$key], $references);
    $assertSame('90', $payment->tPag);
    $assertSame('0.00', $payment->vPag);
    $assertSame(false, property_exists($payment, 'indPag'));
    $assertSame(false, property_exists($paymentGroup, 'vTroco'));

    $make = new \NFePHP\NFe\Make(10);
    $info = new stdClass();
    $info->versao = '4.00';
    $make->taginfNFe($info);
    $ideNode = $make->tagide($ide);
    $referenceNode = $make->tagrefNFe($controller->reference($references[0]));
    $make->tagpag($paymentGroup);
    $paymentNode = $make->tagdetPag($payment);

    $nodeXml = static fn(\DOMNode $node): string => (string) $node->ownerDocument->saveXML($node);
    $ideXml = $nodeXml($ideNode);
    $referenceXml = $nodeXml($referenceNode);
    $paymentXml = $nodeXml($paymentNode);

    $assertTrue(str_contains($ideXml, '<finNFe>1</finNFe>'), 'Fragmento ide não contém finNFe=1.');
    $assertTrue(str_contains($ideXml, '<indPres>0</indPres>'), 'Fragmento ide não contém indPres=0.');
    $assertTrue(
        str_contains($referenceXml, '<refNFe>' . $key . '</refNFe>'),
        'Fragmento NFref não contém a chave normalizada.'
    );
    $assertTrue(str_contains($paymentXml, '<tPag>90</tPag>'), 'Fragmento detPag não contém tPag=90.');
    $assertTrue(str_contains($paymentXml, '<vPag>0.00</vPag>'), 'Fragmento detPag não contém vPag=0.00.');
    $assertSame(false, str_contains($paymentXml, '<indPag>'), 'Fragmento detPag contém indPag indevido.');
});

$test('retorno de conserto rejeita referência, presença e pagamento 90 inválidos', static function () use ($assertSame, $assertTrue): void {
    $controller = new class extends CRT1Controller {
        public function __construct()
        {
        }

        public function validateContract(array $data): void
        {
            $this->validateEmissionData($data);
        }
    };

    $payload = [
        'cnpj' => '01272713000177',
        'cfop' => '5916',
        'finalidade' => 1,
        'indicador_presenca' => 8,
        'nota_referencia' => '123',
        'cliente' => [
            'nome' => 'CLIENTE TESTE',
            'tipo_documento' => 'CNPJ',
            'documento' => '10962697000720',
            'endereco' => [
                'logradouro' => 'RUA TESTE', 'numero' => '1', 'bairro' => 'CENTRO',
                'codigo_municipio' => '2911105', 'municipio' => 'Formosa do Rio Preto',
                'uf' => 'BA', 'cep' => '47990000',
            ],
        ],
        'produtos' => [[
            'codigo' => '98', 'ean' => null, 'descricao' => 'PRODUTO TESTE', 'ncm' => '84295199',
            'cfop' => '5916', 'unidade' => 'UN', 'quantidade' => 1, 'valor' => 100, 'total' => 100, 'origem' => 0,
        ]],
        'total' => 100,
        'total_pago' => 100,
        'troco' => 10,
        'pagamentos' => [
            ['codigo' => '90', 'valorpago' => 10],
            ['codigo' => '01', 'valorpago' => 90],
        ],
    ];

    try {
        $controller->validateContract($payload);
        throw new RuntimeException('Contrato fiscal inválido foi aceito.');
    } catch (HttpException $exception) {
        $assertSame(422, $exception->status());
        $tags = $exception->context()['error_tags'] ?? [];
        $joined = implode(' ', $tags);
        $assertTrue(str_contains($joined, 'indicador_presenca'));
        $assertTrue(str_contains($joined, 'nota_referencia'));
        $assertTrue(str_contains($joined, 'deve ser informada sozinha'));
        $assertTrue(str_contains($joined, 'valor pago igual a zero'));
        $assertTrue(str_contains($joined, 'troco'));
    }
});

$test('validação fiscal explica CFOP interestadual e CSOSN incompatível', static function () use ($assertSame, $assertTrue): void {
    $controller = (new ReflectionClass(CRT1Controller::class))->newInstanceWithoutConstructor();
    $company = new class {
        public function getUf(): string
        {
            return 'AM';
        }

        public function getCrt(): int
        {
            return 1;
        }
    };
    $property = new ReflectionProperty(CRT1Controller::class, 'company');
    $property->setAccessible(true);
    $property->setValue($controller, $company);

    $method = new ReflectionMethod(CRT1Controller::class, 'validateEmissionContext');
    $method->setAccessible(true);

    try {
        $method->invoke($controller, [
            'cfop' => '5102',
            'cliente' => ['endereco' => ['uf' => 'RJ']],
            'produtos' => [['cfop' => '5102', 'cst_icms' => '00']],
        ]);
        throw new RuntimeException('Dados fiscais incompatíveis foram aceitos.');
    } catch (HttpException $exception) {
        $assertSame(422, $exception->status());
        $tags = $exception->context()['error_tags'] ?? [];
        $assertTrue((bool) array_filter($tags, static fn($tag) => str_contains($tag, 'Use um CFOP iniciado por 6')));
        $assertTrue((bool) array_filter($tags, static fn($tag) => str_contains($tag, 'CSOSN 00 inválido')));
    }
});

$test('falha de emissão sempre produz error_tags sem expor segredo', static function () use ($assertTrue): void {
    $controller = (new ReflectionClass(CRT1Controller::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(CRT1Controller::class, 'emissionErrorTags');
    $method->setAccessible(true);
    $tags = $method->invoke(
        $controller,
        new RuntimeException('SQLSTATE conexão recusada password=segredo'),
        'inicialização'
    );

    $assertTrue($tags !== []);
    $assertTrue(!str_contains(implode(' ', $tags), 'segredo'));
});

$test('erro TLS da NFC-e explica recusa do certificado pela SEFAZ', static function () use ($assertSame, $assertTrue): void {
    $controller = (new ReflectionClass(CupomFiscalController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(CupomFiscalController::class, 'emissionErrorTags');
    $method->setAccessible(true);
    $tags = $method->invoke(
        $controller,
        new RuntimeException('SSL routines: ssl3_read_bytes: sslv3 alert certificate unknown'),
        'comunicação com a SEFAZ'
    );

    $joined = implode(' ', $tags);
    $assertTrue($tags !== []);
    $assertTrue(str_contains($joined, 'SEFAZ recusou o certificado'));
    $assertSame(false, str_contains($joined, 'sslv3'));
});

$test('rejeição da SEFAZ não é duplicada em error_tags', static function () use ($assertSame): void {
    $controller = new class extends CRT1Controller {
        public function __construct()
        {
        }

        public function analyze(object $response): void
        {
            $this->analisaRetorno($response);
        }
    };

    // A suíte já escreveu resultados no STDOUT quando este teste roda; em CLI,
    // isso faria header() emitir um warning antes do JSON capturado.
    set_error_handler(static fn(): bool => true, E_WARNING);
    ob_start();
    try {
        $controller->analyze((object) [
            'cStat' => 204,
            'xMotivo' => 'Rejeição: Duplicidade de NF-e [nRec: 310000133336764]',
        ]);
        $response = json_decode((string) ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        ob_end_clean();
        restore_error_handler();
    }

    $assertSame(204, $response['codigo']);
    $assertSame('Rejeição: Duplicidade de NF-e [nRec: 310000133336764]', $response['error']);
    $assertSame([], $response['error_tags']);
});

$test('lote síncrono com uma NF-e encaminha infProt com cStat', static function () use ($assertSame): void {
    $controller = new class extends CRT1Controller {
        public array $received = [];

        public function __construct()
        {
        }

        public function processBatch(object $response): void
        {
            $this->loteProcessado($response);
        }

        protected function analisaRetorno($std): void
        {
            $this->received[] = $std;
        }
    };

    $controller->processBatch((object) [
        'cStat' => '104',
        'protNFe' => (object) [
            'attributes' => (object) ['versao' => '4.00'],
            'infProt' => (object) [
                'cStat' => '100',
                'xMotivo' => 'Autorizado o uso da NF-e',
            ],
        ],
    ]);

    $assertSame(1, count($controller->received));
    $assertSame('100', $controller->received[0]->cStat);
});

$test('lote síncrono com uma NFC-e encaminha infProt com cStat', static function () use ($assertSame): void {
    $controller = new class extends CupomFiscalController {
        public array $received = [];

        public function __construct()
        {
        }

        public function processBatch(object $response): void
        {
            $this->loteProcessado($response);
        }

        protected function analisaRetorno($std): void
        {
            $this->received[] = $std;
        }
    };

    $controller->processBatch((object) [
        'cStat' => '104',
        'protNFe' => (object) [
            'attributes' => (object) ['versao' => '4.00'],
            'infProt' => (object) [
                'cStat' => '100',
                'xMotivo' => 'Autorizado o uso da NFC-e',
            ],
        ],
    ]);

    $assertSame(1, count($controller->received));
    $assertSame('100', $controller->received[0]->cStat);
});

$failures = 0;
foreach ($tests as [$name, $callback]) {
    try {
        $callback();
        echo "[PASS] {$name}\n";
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$name}: {$exception->getMessage()}\n");
    }
}

echo sprintf("%d tests, %d failures.\n", count($tests), $failures);
exit($failures === 0 ? 0 : 1);
