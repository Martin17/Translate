<?php

/**
 * @namespace
 */
namespace Service\Text;

/**
 * Simple PHP class to translate text using the free services of Bing and Google.
 * @author Martín Panizzo <martin@fotolounge.com.ar>
 * @version 0.1
 */

class Translate {
	const GOOGLE_URL = "http://translate.google.com/translate_a/t";
	const BING_URL = "http://api.microsofttranslator.com/V2/Http.svc";
	const BING_AUTH_URL = "https://datamarket.accesscontrol.windows.net/v2/OAuth2-13/";
	const BING_SCOPE_URL = "http://api.microsofttranslator.com";
	const BING_GRANT_TYPE = "client_credentials";

	public $translationService = "google";
	protected $useProxy = FALSE;
	private $proxyHost;
	private $proxyPort;
	protected $useCookie = FALSE;
	private $cookie;
	//<---- chequear si es necesario en google
	private $url;
	public $sourceLang = '';
	public $translateLang = 'es';
	public $lastSourceString;
	public $status;
	private $bingClientID;
	private $bingClientSecret;

	function __construct($options = null) {
		if (is_array($options) || is_object($options)) {
			foreach ($options as $key => $value) {
				$this -> $key = $value;
			}
		}
		if ($this -> translationService == "google") {
			$this -> setUrl($this::GOOGLE_URL);
		} else if ($this -> translationService == "bing") {
			$this -> setUrl($this::BING_URL);
		}
	}

	public function setUrl($url) {
		$this -> url = $url;
	}

	public function setTranslationService($translationService) {
		$this -> translationService = $translationService;
		if ($this -> translationService == "google") {
			$this -> setUrl($this::GOOGLE_URL);
			unset($this -> bingClientID, $this -> bingClientSecret);

		} else if ($this -> translationService == "bing") {
			$this -> setUrl($this::BING_URL);

		}
	}

	public function setTranslationLangs($sourceLang, $translateLang) {
		$this -> sourceLang = $sourceLang;
		$this -> translateLang = $translateLang;
	}

	public function setProxy($host, $port) {
		if (isset($host, $port)) {
			$this -> proxyHost = $host;
			$this -> proxyPort = $port;
			$this -> useProxy = TRUE;
		} else {
			throw new \Exception("No se puede definir el proxy porque faltan parámetros", 5001);

		}

	}

	public function setCookie() {
		$this -> cookie = tempnam("/tmp", "CURLCOOKIE");
		if ($this -> cookie === FALSE)
			throw new \Exception("No se puede escribir el archivo temporal.", 4001);

		$ch = curl_init($this -> url);
		if ($this -> useProxy === TRUE) {
			curl_setopt($ch, CURLOPT_PROXY, $this -> proxyHost);
			curl_setopt($ch, CURLOPT_PROXYPORT, $this -> proxyPort);
		}
		curl_setopt($ch, CURLOPT_COOKIEJAR, $this -> cookie);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$output = curl_exec($ch);
		if (curl_exec($ch) === false) {
			throw new \Exception('No se ha podido inicializar el cookie, al conectar se registró el siguiente error: ' . curl_error($ch), 5101);
		} else {
			if ($this -> useCookie === FALSE)
				$this -> useCookie = TRUE;
			return $output;
		}
	}

	public function setBingData($bingClientID, $bingClientSecret) {
		$this -> bingClientID = $bingClientID;
		$this -> bingClientSecret = $bingClientSecret;
	}
	private function __getBingAuthToken() {
		if (!isset($this -> bingClientID, $this -> bingClientSecret))
			throw new \Exception("No se puede iniciar el servicio Bing porque faltan parámetros", 5005);

		$ch = curl_init();
		$paramArr = array(
			'grant_type' => $this::BING_GRANT_TYPE,
			'scope' => $this::BING_SCOPE_URL,
			'client_id' => $this -> bingClientID,
			'client_secret' => $this -> bingClientSecret
		);
		$paramArr = http_build_query($paramArr);

		if ($this -> useProxy === TRUE) {
			curl_setopt($ch, CURLOPT_PROXY, $this -> proxyHost);
			curl_setopt($ch, CURLOPT_PROXYPORT, $this -> proxyPort);
		}
		curl_setopt($ch, CURLOPT_URL, $this::BING_AUTH_URL);
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $paramArr);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		$strResponse = curl_exec($ch);
		$curlErrno = curl_errno($ch);
		if ($curlErrno) {
			$curlError = curl_error($ch);
			throw new \Exception($curlError);
		}
		curl_close($ch);
		$objResponse = json_decode($strResponse);
		if (isset($objResponse -> error)) {
			throw new \Exception($objResponse -> error_description);
		}
		return $objResponse -> access_token;
	}

	protected function __translateToBing($stringToTranslate) {
		$token = $this -> __getBingAuthToken();
		$authHeader = "Authorization: Bearer " . $token;
		$params = "text=" . urlencode($stringToTranslate) . "&to=" . $this -> translateLang;
		if (isset($this -> sourceLang))
			$params .= "&from=" . $this -> sourceLang;
		$url = "$this->url/Translate?$params";
		$ch = curl_init();
		if ($this -> useProxy === TRUE) {
			curl_setopt($ch, CURLOPT_PROXY, $this -> proxyHost);
			curl_setopt($ch, CURLOPT_PROXYPORT, $this -> proxyPort);
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			$authHeader,
			"Content-Type: text/xml"
		));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, False);
		$curlResponse = curl_exec($ch);
		$curlErrno = curl_errno($ch);
		if ($curlErrno) {
			$this -> status = "error";
			$curlError = curl_error($ch);
			throw new \Exception($curlError);
		}
		$this -> status = "success";
		curl_close($ch);
		return $curlResponse;

	}

	protected function __translateToGoogle($stringToTranslate) {

		if ($this -> useCookie === TRUE) {
			$this -> setCookie();
		}

		$params = array(
			"client" => "t",
			"text" => $stringToTranslate,
			"hl" => $this -> translateLang,
			"sl" => $this -> sourceLang || '',
			"tl" => $this -> translateLang,
			"ie" => "UTF-8",
			"oe" => "UTF-8",
			"multires" => "1",
			"otf" => "1",
			"pc" => "1",
			"trs" => "1",
			"ssel" => "3",
			"tsel" => "6",
			"sc" => "1"
		);
		$params_arr = array();
		foreach ($params as $key => $value) {
			$params_arr[] = urlencode($key) . "=" . urlencode($value);
		}
		$params_str = (!empty($params_arr)) ? '?' . implode('&', $params_arr) : "";
		$url = $this -> url . $params_str;
		$ch = curl_init($url);
		if ($this -> useProxy === TRUE) {
			curl_setopt($ch, CURLOPT_PROXY, $this -> proxyHost);
			curl_setopt($ch, CURLOPT_PROXYPORT, $this -> proxyPort);
		}
		if ($this -> useCookie === TRUE)
			curl_setopt($ch, CURLOPT_COOKIEJAR, $this -> cookie);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$curl_output = curl_exec($ch);
		if (curl_exec($ch) === false) {
			$this -> status = "error";
			throw new \Exception('No se ha podido traducir el texto, al conectar se registró el siguiente error: ' . curl_error($ch), 5101);
		}
		//var_dump($curl_output);
		$this -> status = "success";
		$curl_output = explode('"', $curl_output);
		$output = urldecode($curl_output[1]);
		return $output;

	}

	public function getTranslation($stringToTranslate) {
		$allowedService = array(
			"google",
			"bing"
		);
		if (!in_array($this -> translationService, $allowedService))
			throw new \Exception('No se puede iniciar el servicio requerido porque no se reconoce el mismo', 3001);
		$this -> status = "busy";
		if ($this -> translationService == "google") {
			$string = explode(".", $stringToTranslate);
			foreach ($string as $key => $value) {
				$string[$key] = $this -> __translateToGoogle($value);
			}
			$translation = implode(". ", $string);
		}
		if ($this -> translationService == "bing") {
			if (strlen($stringToTranslate) > 1000) {
				$string = explode(".", $stringToTranslate);
				foreach ($string as $key => $value) {
					$string[$key] = $this -> __translateToBing($value);
				}
				$translation = implode(". ", $string);
			} else {
				$translation = $this -> __translateToBing($stringToTranslate);
			}
		}
		$this -> lastSourceString = $stringToTranslate;
		$output = rtrim($translation);
		return $output;
	}

}
