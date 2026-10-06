// Run by trace-tarball-redirect.sh through `php artisan tinker --execute`.
// Downloads one commit archive with PrivateCloud's own GitHubClient and the saved
// GitHub connection, and records every HTTP request the client really sends,
// including the ones made while following redirects (the middleware sits inside
// Guzzle's redirect handling). Only the host and whether an Authorization header
// was present are recorded; header values are never read or printed.

$repository = (string) getenv('PC_TRACE_REPO');
$sha = (string) getenv('PC_TRACE_SHA');
$hops = [];

Illuminate\Support\Facades\Http::globalRequestMiddleware(function ($request) use (&$hops) {
    $hops[] = ['host' => $request->getUri()->getHost(), 'auth' => $request->hasHeader('Authorization')];

    return $request;
});

$client = App\Services\Source\GitHubClient::forConnection();
$destination = tempnam(sys_get_temp_dir(), 'pc-trace-');
$bytes = 0;
$error = null;
try {
    $client->downloadTarball($repository, $sha, $destination, 120);
    $bytes = (int) filesize($destination);
} catch (Throwable $e) {
    $error = $e->getMessage();
} finally {
    @unlink($destination);
}

$apiHost = (string) parse_url((string) config('privatecloud.github.api_url'), PHP_URL_HOST);
echo 'GitHub connection saved: '.(App\Models\GithubConnection::current() ? 'yes' : 'no').PHP_EOL;
foreach ($hops as $i => $hop) {
    printf("request %d  %-32s Authorization header: %s\n", $i + 1, $hop['host'], $hop['auth'] ? 'SENT' : 'not sent');
}
echo $error === null ? "archive downloaded: {$bytes} bytes".PHP_EOL : "download failed: {$error}".PHP_EOL;

$offApi = array_values(array_filter($hops, fn ($hop) => $hop['host'] !== $apiHost));
$leaks = array_filter($offApi, fn ($hop) => $hop['auth']);
echo match (true) {
    $leaks !== [] => 'RESULT: FAIL (the Authorization header reached a host other than '.$apiHost.')',
    $error !== null => 'RESULT: INCONCLUSIVE (the download failed; nothing leaked, but the redirect was not exercised fully)',
    $offApi === [] => 'RESULT: INCONCLUSIVE (no redirect away from '.$apiHost.' happened)',
    default => 'RESULT: PASS (redirected to '.$offApi[0]['host'].' without the Authorization header)',
}.PHP_EOL;
