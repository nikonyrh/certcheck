<?php
$json = function($result) {
	header('Content-Type: application/json');
	die(json_encode($result));
};

$hash = function ($str, $alg) { return hash($alg, $str, false); };

$thumbprints = function($cert) use ($hash) {
	$cert = preg_split('/\-+(BEGIN|END) CERTIFICATE\-+/', $cert);
	$cert = base64_decode(str_replace(array("\n\r","\n","\r"), '', $cert[1]));
	
	$format = function ($cert) {
		return rtrim(chunk_split(strtoupper($cert), 2, ':'), ':');
	};
	
	return array(
		'SHA-1'   => $format($hash($cert, 'sha1')),
		'SHA-256' => $format($hash($cert, 'sha256'))
	);
};

if (!isset($_GET['host'])) {
	die(file_get_contents('index_contents.html'));
}

$host = strtolower($_GET['host']);
$host = preg_replace('_^([a-z]+://)?([^/]+).*_', '\2', $host);
$cert = null;

if (!preg_match('/.\.[a-z0-9]{1,}$/', $host)) {
	$json(array(
		'error'   => true,
		'message' => "Invalid host '$host'"
	));
}

$response = isset($_GET['nocache']) ? null : apc_fetch($host);

if (!$response) {
	if ($fp = tmpfile()) {
		// http://stackoverflow.com/a/3817143/3731823
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
	
	apc_store($host, $response, 15*60); // 15 min
	$response['cached'] = false;
}
else {
	$response['cached'] = true;
}

$response['now'] = date(DATE_ATOM);

$json($response);
