<?php
declare(strict_types=1);
$base = $argv[1] ?? 'http://127.0.0.1:8080';
$fail = 0;
function req(string $method, string $url, ?string $body = null, array $headers = [], ?string $cookie = null): array {
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    if ($cookie) $h[] = 'Cookie: ' . $cookie;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [0, '', '', $err];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$status, substr($raw, 0, $hs), substr($raw, $hs), null];
}
function check(string $name, int $status, $expect, string $body, ?string $err = null): void {
    global $fail;
    $ok = $err === null && $status === $expect;
    if (is_callable($expect)) {
        $ok = $err === null && $expect($status, $body);
    } elseif (is_array($expect) && isset($expect[0])) {
        $ok = $err === null && in_array($status, $expect, true);
    }
    echo ($ok ? 'OK  ' : 'FAIL') . " $name status=$status" . ($err ? " err=$err" : '') . "\n";
    if (!$ok) {
        $fail++;
        echo substr($body, 0, 300) . "\n";
    }
}
[$s, $h, $b, $e] = req('GET', $base . '/');
check('SPA /', $s, 200, $b, $e);
if (!str_contains($b, 'id="root"')) { echo "FAIL SPA missing root\n"; $fail++; }
[$s, $h, $b, $e] = req('GET', $base . '/api/auth/status');
check('auth status', $s, 200, $b, $e);
$st = json_decode($b, true);
if (($st['github'] ?? true) !== false || ($st['password'] ?? false) !== true) { echo "FAIL auth status body $b\n"; $fail++; }
[$s, $h, $b, $e] = req('GET', $base . '/api/feed');
check('empty feed', $s, 200, $b, $e);
$feed = json_decode($b, true);
if (($feed['size'] ?? -1) !== 0) { echo "FAIL feed not empty $b\n"; $fail++; }
[$s, $h, $b, $e] = req('GET', $base . '/api/config/client/bootstrap.js');
check('bootstrap', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('POST', $base . '/api/auth/login', json_encode(['username'=>'admin','password'=>'wrong']), ['Content-Type: application/json']);
check('bad login', $s, 403, $b, $e);
[$s, $h, $b, $e] = req('POST', $base . '/api/auth/login', json_encode(['username'=>'admin','password'=>'admin123']), ['Content-Type: application/json']);
check('login', $s, 200, $b, $e);
$login = json_decode($b, true);
$token = $login['token'] ?? '';
preg_match('/Set-Cookie:\s*token=([^;]+)/i', $h, $m);
$cookie = isset($m[1]) ? 'token=' . $m[1] : 'token=' . $token;
$auth = ['Content-Type: application/json', 'Authorization: Bearer ' . $token];
[$s, $h, $b, $e] = req('GET', $base . '/api/user/profile', null, ['Authorization: Bearer ' . $token], $cookie);
check('profile', $s, 200, $b, $e);
$payload = json_encode(['title'=>'Smoke','alias'=>'smoke','content'=>'hello **rin**','summary'=>'hi','listed'=>true,'draft'=>false,'tags'=>['php']]);
[$s, $h, $b, $e] = req('POST', $base . '/api/feed', $payload, $auth, $cookie);
check('create feed', $s, 200, $b, $e);
$id = json_decode($b, true)['insertedId'] ?? 0;
[$s, $h, $b, $e] = req('GET', $base . '/api/feed/' . $id);
check('show feed', $s, 200, $b, $e);
$detail = json_decode($b, true);
if (($detail['pv'] ?? 0) < 1) { echo "FAIL pv $b\n"; $fail++; }
[$s, $h, $b, $e] = req('GET', $base . '/api/feed/timeline');
check('timeline', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('GET', $base . '/api/search/Smoke');
check('search', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('GET', $base . '/api/tag');
check('tag list', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('POST', $base . '/api/comment/' . $id, json_encode(['content'=>'nice']), $auth, $cookie);
check('comment', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('GET', $base . '/api/comment/' . $id);
check('comment list', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('POST', $base . '/api/moments', json_encode(['content'=>'moment']), $auth, $cookie);
check('moment', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('GET', $base . '/api/moments');
check('moments', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('POST', $base . '/api/friend', json_encode(['name'=>'Demo','desc'=>'d','avatar'=>'https://example.com/a.png','url'=>'https://example.com']), $auth, $cookie);
check('friend', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('GET', $base . '/api/friend', null, ['Authorization: Bearer ' . $token], $cookie);
check('friends', $s, 200, $b, $e);
$tmp = tempnam(sys_get_temp_dir(), 'rin');
file_put_contents($tmp, 'hello-upload');
$ch = curl_init($base . '/api/storage');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    CURLOPT_COOKIE => $cookie,
    CURLOPT_POSTFIELDS => ['key' => 'test.txt', 'file' => new CURLFile($tmp, 'text/plain', 'test.txt')],
]);
$ub = curl_exec($ch);
$us = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($tmp);
check('upload', $us, 200, (string)$ub);
$up = json_decode((string)$ub, true);
$url = $up['url'] ?? '';
if ($url === '' || !str_contains($url, '/api/blob/')) { echo "FAIL upload url $ub\n"; $fail++; }
else {
    $path = parse_url($url, PHP_URL_PATH);
    [$s, $h, $b, $e] = req('GET', $base . $path);
    check('blob', $s, 200, $b, $e);
}
[$s, $h, $b, $e] = req('GET', $base . '/rss.xml');
check('rss', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('GET', $base . '/atom.xml');
check('atom', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('GET', $base . '/rss.json');
check('rss json', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('GET', $base . '/api/config/health', null, ['Authorization: Bearer ' . $token], $cookie);
check('health', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('GET', $base . '/feed/smoke');
check('SPA feed', $s, 200, $b, $e);
[$s, $h, $b, $e] = req('GET', $base . '/api/user/github');
check('github disabled', $s, 400, $b, $e);
echo $fail === 0 ? "ALL PASSED\n" : "FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
