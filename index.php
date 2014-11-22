<?php
if (!isset($_GET['host'])) {
	readfile('index_contents.html');
	die();
}

$hash = function ($str, $alg) { return strtoupper(hash($alg, $str, false)); };
$json = function($result) {
	header('Content-Type: application/json');
	die(json_encode($result));
};

$words = apc_fetch('certcheck::words');
if (!$words) {
	$words = array_map("trim", file('words.txt'));
	apc_store('certcheck::words', $words, 24*60*60);
}

$thumbprints = function($cert) use ($hash, $words) {
	$cert = preg_split('/\-+(BEGIN|END) CERTIFICATE\-+/', $cert);
	$cert = base64_decode(str_replace(array("\n\r","\n","\r"), '', $cert[1]));
	
	$format = function ($cert) {
		return rtrim(chunk_split($cert, 2, ':'), ':');
	};
	
	$sha1   = $hash($cert, 'sha1');
	$sha256 = $hash($cert, 'sha256');
	
	$wordHash = explode(' ', chunk_split($hash("$sha1/$sha256", 'sha256'), 3, ' '));
	$word     = function ($i) use ($words, $wordHash) {
		return $words[hexdec($wordHash[$i]) % sizeof($words)];
	};
	
	return array(
		'SHA-1'   => $format($sha1),
		'SHA-256' => $format($sha256),
		'WORDS'   => implode(', ', array_map($word, range(0, 6)))
	);
};

$cert = null;
$host = strtolower($_GET['host']);

// Remove the possible protocol section and anything after "/"
$host = preg_replace('_^([a-z]+://)?([^/]+).*_', '\2', $host);

if (!preg_match('/.\.[a-z0-9]{1,}$/', $host)) {
	$json(array(
		'error'   => true,
		'message' => "Invalid host '$host'"
	));
}

$cacheKey = "certcheck::$host";
$response = isset($_GET['nocache']) ? null : apc_fetch($cacheKey);

if (!$response) {
	if ($fp = tmpfile()) {
		// Based on http://stackoverflow.com/a/3817143/3731823
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,"https://$host");
		curl_setopt($ch, CURLOPT_STDERR, $fp);
		curl_setopt($ch, CURLOPT_CERTINFO, 1);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_NOBODY, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		//curl_setopt($ch, CURLOPT_SSLVERSION, 3);
		curl_setopt($ch, CURLOPT_SSLVERSION, 4);
		$response = curl_exec($ch);
		
		if (curl_errno($ch)) {
			$json(array(
				'error'   => true,
				'errno'   => curl_errno($ch),
				'message' => curl_error($ch)
			));
		}
		
		fseek($fp, 0);//rewind
		$cert = '';
		while(strlen($cert .= fread($fp, 8192)) == 8192);
		fclose($fp);
	}
	else {
		$json(array('error' => true, 'message' => 'Failed to create a temporary file'));
	}
	
	$response = array(
		'host'         => $host,
		'fingerprints' => $thumbprints($cert),
		'retrieved'    => date(DATE_ATOM),
		'response'     => preg_split('/[\r\n]+/', trim($response))
	);
	
	apc_store($cacheKey, $response, 15*60); // 15 min
	$response['cached'] = false;
}
else {
	$response['cached'] = true;
}

$response['now'] = date(DATE_ATOM);
$json($response);
