<?php
$url = $argv[1];
$domain = parse_url($url, PHP_URL_HOST);
$domain = preg_replace('|[^a-zA-Z0-9\.]+|', '', $domain);
$pid = shell_exec('nohup chromium-browser --headless --incognito --remote-debugging-port=9222 > /dev/null 2>&1 & echo -n $!');
$data = shell_exec('chrome-har-capturer -g 2000 ' . escapeshellcmd($url) . ' 2>/dev/null');
posix_kill($pid, SIGTERM);
$data = json_decode($data, true);
$domains = [];
$ports = [];
$flags = [];
foreach ($data['log']['entries'] as $entry) {
    if (($entry['request']['url'] ?? null) !== null) {
        $url = parse_url($entry['request']['url']);
        $domain = $url['host'];
        $domains[$domain] = $domain;
        $ports[$domain] = $url['port'] ?? ($url['scheme'] === 'http' ? 80 : 443);
        $flags[$domain] = $flags[$domain] ?? [];

        // no SSL
        if ($url['scheme'] === 'http') {
            $flags[$domain]['no_ssl'] = true;
        }

        // google analytics
        if (in_array($domain, ['www.google-analytics.com'])) {
            if (strpos($url['path'], '/collect') !== false) {
                // parse get and post data
                $query = $entry['request']['queryString'] ?? [];
                $get = array_combine(array_column($query, 'name'), array_column($query, 'value'));
                parse_str($entry['request']['postData']['text'] ?? '', $post);

                // check for aip flag
                if (($get['aip'] ?? false) || ($post['aip'] ?? false)) {
                    $flags[$domain]['ga_aip'] = true;
                } else {
                    $flags[$domain]['ga_no_aip'] = true;
                }
            }
        }

        // google fonts
        if ($domain === 'fonts.googleapis.com') {
            $flags[$domain]['g_fonts'] = true;
        }

        // google doubleclick ads
        if ($domain === 'stats.g.doubleclick.net') {
            $flags[$domain]['g_dc_ads'] = true;
        }

        // facebook connect
        if ($domain === 'connect.facebook.net') {
            $flags[$domain]['fb_connect'] = true;
        }

        // chartbeat analytics
        if ($domain === 'ping.chartbeat.net') {
            $flags[$domain]['chartbeat'] = true;
        }

        // new relic in the US
        if ($domain === 'bam.nr-data.net') {
            $flags[$domain]['nr_in_us'] = true;
        }
    }
}
$domains = array_values(array_unique(array_keys($domains)));
$ips = [];
$hostnames = [];
foreach ($domains as $i => $domain) {
    $ip = gethostbyname($domain);
    if ($ip == $domain) {
        $domains[$i] = null;
    } else {
        $ips[$domain] = $ip;
        $hostname = gethostbyaddr($ip);
        if ($ip == $hostname) {
            $hostnames[$domain] = '';
        } else {
            $hostnames[$domain] = $hostname;
        }
    }
}
$domains = array_values(array_filter($domains));
$pings = [];
for ($i = 0; $i < 3; $i++) {
    foreach ($ips as $domain => $ip) {
        if (!isset($pings[$ip])) {
            $pings[$ip] = 1000;
        }
        usleep(10000);
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $connect_timeval = array(
            "sec" => 1,
            "usec" => 0,
        );
        socket_set_option(
            $socket,
            SOL_SOCKET,
            SO_SNDTIMEO,
            $connect_timeval
        );
        socket_set_option(
            $socket,
            SOL_SOCKET,
            SO_RCVTIMEO,
            $connect_timeval
        );
        $time = 0;
        $port = $ports[$domain];
        $start = microtime(true);
        if ($socket && socket_connect($socket, $ip, $port)) {
            $time = round(1000 * (microtime(true) - $start));
            socket_close($socket);
        }
        if ($time > 0) {
            $pings[$ip] = min($pings[$ip], $time);
        }
    }
}
$locations = [];
if ($ips) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://ip-api.com/batch?fields=continentCode,country,org");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array_values($ips)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $locations = json_decode(curl_exec($ch), true);
    curl_close($ch);
}
$lines = [];
foreach ($domains as $i => $domain) {
    $location = $locations[$i];
    $ip = $ips[$domain];
    $ping = $pings[$ip];
    $hostname = $hostnames[$domain];
    $continent = $location['continentCode'] == 'EU' ? 'EU' : '';
    $country = $location['country'];
    $organization = $location['org'];
    $flag = array_keys($flags[$domain]);
    $line = [$domain, $flag, $ping, $hostname, $continent, $country, $organization];
    $lines[] = $line;
}

foreach ($lines as $line) {
    echo json_encode($line) . "\n";
}
