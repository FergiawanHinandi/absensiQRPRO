<?php
echo "Starting curl multi test" . PHP_EOL;
$mh = curl_multi_init();
$ch1 = curl_init('http://127.0.0.1:8005/api/v1/health');
curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch1);

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

echo "Curl multi finished" . PHP_EOL;
$info = curl_getinfo($ch1);
echo "HTTP Code: " . $info['http_code'] . PHP_EOL;
curl_multi_remove_handle($mh, $ch1);
curl_multi_close($mh);
