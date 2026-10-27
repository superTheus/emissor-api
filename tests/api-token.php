<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$cases = [['', '', 503], ['   ', '', 503], ['test-only-token', '', 401], ['test-only-token', 'wrong', 401], ['test-only-token', 'test-only-token', 200]];
foreach ($cases as [$configured, $provided, $expected]) {
    $code = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
        . '$_ENV["API_TOKEN"]=' . var_export($configured,true) . ';'
        . 'putenv(' . var_export('API_TOKEN=' . $configured,true) . ');'
        . '$_SERVER["HTTP_AUTHORIZATION"]=' . var_export('Bearer ' . $provided,true) . ';'
        . 'http_response_code(200); register_shutdown_function(function(){echo "STATUS=".http_response_code();});'
        . 'App\\Http\\ApiTokenMiddleware::handle();';
    $process = proc_open([PHP_BINARY, '-r', $code], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    if ($exit !== 0 || !str_ends_with($output, 'STATUS=' . $expected)) throw new RuntimeException('Token middleware: ' . $expected . ' failed: ' . $error);
}
echo "Token authentication: 5 cases passed\n";
