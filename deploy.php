<?php
// Run by cPanel cron every 10 minutes.
// Checks GitHub for a new commit; if there is one, downloads the repo as a zip
// and copies its files into public_html. Needs no git or shell commands.
$repo   = 'mostafashena/sitesbyms-previews';
$branch = 'main';
$home   = getenv('HOME') ?: dirname(__DIR__, 2);
$target = $home . '/public_html';
$state  = $home . '/.sitesbyms_last_sha';
$skip   = ['deploy.php', 'README.md'];

function fetch($url) {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'sitesbyms-deploy', CURLOPT_TIMEOUT => 120]);
        $out = curl_exec($c);
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        return ($out !== false && $code < 400) ? $out : false;
    }
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: sitesbyms-deploy\r\n", 'timeout' => 120]]);
    return @file_get_contents($url, false, $ctx);
}

$info = fetch("https://api.github.com/repos/$repo/commits/$branch");
$sha  = $info ? (json_decode($info, true)['sha'] ?? '') : '';
if ($sha === '') { fwrite(STDERR, "Could not read latest commit\n"); exit(1); }
if (is_file($state) && trim(file_get_contents($state)) === $sha) exit(0); // nothing new

$zipData = fetch("https://codeload.github.com/$repo/zip/refs/heads/$branch");
if ($zipData === false) { fwrite(STDERR, "Download failed\n"); exit(1); }
$tmp = tempnam(sys_get_temp_dir(), 'sbm');
file_put_contents($tmp, $zipData);

$zip = new ZipArchive();
if ($zip->open($tmp) !== true) { fwrite(STDERR, "Bad zip\n"); exit(1); }
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    $rel  = substr($name, strpos($name, '/') + 1);           // strip "repo-branch/" prefix
    if ($rel === '' || strpos($rel, '..') !== false) continue;
    if (in_array($rel, $skip, true)) continue;
    $dest = $target . '/' . $rel;
    if (substr($rel, -1) === '/') { if (!is_dir($dest)) mkdir($dest, 0755, true); continue; }
    if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
    file_put_contents($dest, $zip->getFromIndex($i));
}
$zip->close();
unlink($tmp);
file_put_contents($state, $sha);
echo "Deployed $sha\n";
