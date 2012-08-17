<?php

/**
 * @namespace
 */

namespace Service\Text;

/**
 * Simple PHP class to translate text using the free services of Bing and Google.
 * @author Martín Panizzo <martin@fotolounge.com.ar>
 * @version 0.1-alpha
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 * 
 */
class Translate {
    /**
     * @var string Google URL for translation service
     */

    const GOOGLE_URL = "http://translate.google.com/translate_a/t";
    /**
     * @var string Bing URL for translation service
     */
    const BING_URL = "http://api.microsofttranslator.com/V2/Http.svc";
    /**
     * @var string Bing OAuth URL, needed for generate auth token.
     */
    const BING_AUTH_URL = "https://datamarket.accesscontrol.windows.net/v2/OAuth2-13/";
    /**
     * @var string Bing Scope URL, needed for generate auth token.
     */
    const BING_SCOPE_URL = "http://api.microsofttranslator.com";
    /**
     * @var string Bing grant type, needed for generate auth token.
     */
    const BING_GRANT_TYPE = "client_credentials";

    /**
     * 
     * @var string Translation service to use. Valid options: "bing","google". Default: "google"
     * @see setTranslationService()
     */
    public $translationService = "google";

    /**
     * A valid ISO 639-1 Language Code for the text to translate. 
     * 
     * If empty, the service automatically detects the language used.
     * 
     * @var string 
     * @see setTranslationLangs()
     */
    public $sourceLang = '';

    /**
     * A valid ISO 639-1 Language Code for the language you want to translate the text.
     * 
     * This var can't be empty.
     * @var string 
     * @see setTranslationLangs()
     */
    public $translateLang = 'es';

    /**
     * @var bool Set to TRUE if the server is running behind a proxy.
     * @see setProxy()
     */
    protected $useProxy = FALSE;

    /**
     * @var string Host of the proxy server. Needed if $useProxy if set to TRUE. 
     * @see setProxy()
     */
    private $proxyHost;

    /**
     * @var mixed Port  of the proxy server. Needed if $useProxy if set to TRUE. 
     * @see setProxy()
     */
    private $proxyPort;

    /**
     *
     * @var bool Sometimes Google Translate need set a cookie... Need to test this! 
     */
    protected $useCookie = FALSE;

    /**
     *
     * @var binary cookie generated if $useCookie is set to TRUE
     */
    private $cookie;

    /**
     *
     * @var string URL of the translation service to use.
     */
    private $url;

    /**
     * You need to enable the Microsoft Translator API to use the service. The service is free to the limitation of 2000000 characters per month and requests should not exceed 1000 characters at a time.
     *
     * @var string Client ID provided by the Windows Azure Marketplace. You need to enable the Microsoft Translator API to use the service.
     * @link http://www.microsofttranslator.com/dev/  Microsoft Translator for devs.
     * @see setBingData()
     */
    private $bingClientID;

    /**
     * You need to enable the Microsoft Translator API to use the service. The service is free to the limitation of 2000000 characters per month and requests should not exceed 1000 characters at a time.
     * 
     * @var string Secret phrase provided by the  Windows Azure Marketplace. You need to enable the Microsoft Translator API to use the service.
     * @link http://www.microsofttranslator.com/dev/  Microsoft Translator for devs.
     * @see setBingData()
     */
    private $bingClientSecret;

    /**
     * @var string Original text to translate. 
     */
    private $lastSourceString;

    /**
     * @var string Status of the translation. 
     */
    private $status;

    /**
     * Class constructor. Accepts an object or an array of options.
     * 
     * @param array|object $options Object or array of options passed to the class constructor.
     */
    function __construct($options = null) {
        if (is_array($options) || is_object($options)) {
            foreach ($options as $key => $value) {
                $this->$key = $value;
            }
        }
        if ($this->translationService == "google") {
            $this->setUrl($this::GOOGLE_URL);
        } else if ($this->translationService == "bing") {
            $this->setUrl($this::BING_URL);
        }
    }

    /**
     * Set the URL of the service translation selected.
     * 
     * @param string $url URL of the service translation.
     */
    private function setUrl($url) {
        $this->url = $url;
    }

    /**
     * Method to set the Auth token needed for connect to the Bing service.
     * @return string
     * @throws \Exception
     */
    private function __getBingAuthToken() {
        if (!isset($this->bingClientID, $this->bingClientSecret))
            throw new \Exception("No se puede iniciar el servicio Bing porque faltan parámetros", 5005);

        $ch = curl_init();
        $paramArr = array(
            'grant_type' => $this::BING_GRANT_TYPE,
            'scope' => $this::BING_SCOPE_URL,
            'client_id' => $this->bingClientID,
            'client_secret' => $this->bingClientSecret
        );
        $params = http_build_query($paramArr);

        if ($this->useProxy === TRUE) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxyHost);
            curl_setopt($ch, CURLOPT_PROXYPORT, $this->proxyPort);
        }
        curl_setopt($ch, CURLOPT_URL, $this::BING_AUTH_URL);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
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
        if (isset($objResponse->error)) {
            throw new \Exception($objResponse->error_description);
        }
        return $objResponse->access_token;
    }

    /**
     * Method to translate with de Bing Service.
     * 
     * If the length of the text to translate exceed 1000 characters, the text is splitted in phrases to translate one at time.
     * 
     * @param string $stringToTranslate Text to translate.
     * @return string The translated text.
     * @throws \Exception Throws exception if CURL register an error.
     */
    protected function __translateToBing($stringToTranslate) {
        $token = $this->__getBingAuthToken();
        $authHeader = "Authorization: Bearer " . $token;
        $params = "text=" . urlencode($stringToTranslate) . "&to=" . $this->translateLang;
        if (isset($this->sourceLang))
            $params .= "&from=" . $this->sourceLang;
        $url = "$this->url/Translate?$params";
        $ch = curl_init();
        if ($this->useProxy === TRUE) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxyHost);
            curl_setopt($ch, CURLOPT_PROXYPORT, $this->proxyPort);
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
            $this->status = "error";
            $curlError = curl_error($ch);
            throw new \Exception('No se ha podido traducir el texto, al conectar se registró el siguiente error: ' . $curlError, 5101);
        }
        $this->status = "success";
        curl_close($ch);
        return $curlResponse;
    }

    /**
     * Method to translate with de Google Service.
     * 
     * Ok, Google Translation service is not free anymore... 
     * But we can connect to the online free service with this little {@link http://rupeshpatel.wordpress.com/2012/06/23/usage-of-google-translator-api-for-free/ trick}!
     * The text is divided into phrases to translate one by one. 
     * This is due to free online API responds with a multidimensional array with the options of translation of each sentence, 
     * making it difficult to generate the text again.
     * 
     * @param string $stringToTranslate Text to translate.
     * @return string The translated text.
     * @throws \Exception Throws exception if CURL register an error.
     */
    protected function __translateToGoogle($stringToTranslate) {

        if ($this->useCookie === TRUE) {
            $this->setCookie();
        }

        $params = array(
            "client" => "t",
            "text" => $stringToTranslate,
            "hl" => $this->translateLang,
            "sl" => $this->sourceLang || '',
            "tl" => $this->translateLang,
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
        $url = $this->url . $params_str;
        $ch = curl_init($url);
        if ($this->useProxy === TRUE) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxyHost);
            curl_setopt($ch, CURLOPT_PROXYPORT, $this->proxyPort);
        }
        if ($this->useCookie === TRUE)
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $curl_output = curl_exec($ch);
        if (curl_exec($ch) === false) {
            $this->status = "error";
            throw new \Exception('No se ha podido traducir el texto, al conectar se registró el siguiente error: ' . curl_error($ch), 5101);
        }
        $this->status = "success";
        $curl_output_arr = explode('"', $curl_output);
        $output = urldecode($curl_output_arr[1]);
        return $output;
    }

    /**
     * Set or change the translation service to use.
     * @param string $translationService The srvice to use. Can be "google" or "bing"
     * @throws \Exception
     */
    public function setTranslationService($translationService) {
        $this->translationService = $translationService;
        if ($this->translationService == "google") {
            $this->setUrl($this::GOOGLE_URL);
            unset($this->bingClientID, $this->bingClientSecret);
        } else if ($this->translationService == "bing") {
            $this->setUrl($this::BING_URL);
        } else {
            throw new \Exception('El servicio requerido no es reconocido como válido', 3009);
        }
    }

    /**
     * Set or change the language translated and the language to translate.
     * If the source language is NULL or empty, the service automatically detects the language used.
     * List of supported Language Codes: <ul><li>{@link http://msdn.microsoft.com/en-us/library/hh456380.aspx Microsoft Translator}</li></ul>
     * 
     * @param string $sourceLang A valid ISO 639-1 Language Code for the text to translate. 
     * @param string $translateLang  A valid ISO 639-1 Language Code for the language you want to translate the text.
     * @throws \Exception Throws exception if $translateLang is not defined.
     */
    public function setTranslationLangs($sourceLang, $translateLang) {
        if (!isset($translateLang) || $translateLang == "")
            throw new \Exception('Debe indicar el lenguaje al cual desea traducir el texto', 1010);
        $this->sourceLang = $sourceLang;
        $this->translateLang = $translateLang;
    }

    /**
     * Set a valid proxy for connect if needed.
     * @param string $host
     * @param mixed $port
     * @throws \Exception
     */
    public function setProxy($host, $port) {
        if (isset($host, $port)) {
            $this->proxyHost = $host;
            $this->proxyPort = $port;
            $this->useProxy = TRUE;
        } else {
            throw new \Exception("No se puede definir el proxy porque faltan parámetros", 5001);
        }
    }

    /**
     * Set a cookie for the connection to Google. 
     * @return binary
     * @throws \Exception
     */
    public function setCookie() {
        $this->cookie = tempnam("/tmp", "CURLCOOKIE");
        if ($this->cookie === FALSE)
            throw new \Exception("No se puede escribir el archivo temporal.", 4001);

        $ch = curl_init($this->url);
        if ($this->useProxy === TRUE) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxyHost);
            curl_setopt($ch, CURLOPT_PROXYPORT, $this->proxyPort);
        }
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($ch);
        if (curl_exec($ch) === false) {
            throw new \Exception('No se ha podido inicializar el cookie, al conectar se registró el siguiente error: ' . curl_error($ch), 5101);
        } else {
            if ($this->useCookie === FALSE)
                $this->useCookie = TRUE;
            return $output;
        }
    }

    /**
     * You can set the parameters <tt>$bingClientID</tt> and <tt>$bingClientSecret</tt> if they were not defined in the class constructor. Required to use the Bing service.
     * 
     * You need to enable the Microsoft Translator API to use the service. 
     * The service is free to the limitation of 2000000 characters per month and requests should not exceed 1000 characters at a time.
     * 
     * @param string $bingClientID Client ID provided by the  Windows Azure Marketplace. 
     * @param string $bingClientSecret Secret phrase provided by the  Windows Azure Marketplace. 
     */
    public function setBingData($bingClientID, $bingClientSecret) {
        $this->bingClientID = $bingClientID;
        $this->bingClientSecret = $bingClientSecret;
    }

    /**
     * Return last text translated.
     * @return string
     */
    public function getLastSourceTranslatedText() {
        return $this->lastSourceString;
    }

    /**
     * Return succes or error.
     * @return string
     */
    public function getTranlationStatus() {
        return $this->status;
    }

    /**
     * Main method to translate. 
     * This method calls the corresponding method as defined for the property <tt>translationService</tt>
     * 
     * @param string $stringToTranslate Text to translate
     * @return string
     * @throws \Exception
     */
    public function getTranslation($stringToTranslate) {
        $allowedService = array(
            "google",
            "bing"
        );
        if (!in_array($this->translationService, $allowedService))
            throw new \Exception('No se puede iniciar el servicio requerido porque no se reconoce el mismo', 3001);
        $this->status = "busy";
        if ($this->translationService == "google") {
            $string = explode(".", $stringToTranslate);
            foreach ($string as $key => $value) {
                $string[$key] = $this->__translateToGoogle($value);
            }
            $translation = implode(". ", $string);
        }
        if ($this->translationService == "bing") {
            if (strlen($stringToTranslate) > 1000) {
                $string = explode(".", $stringToTranslate);
                foreach ($string as $key => $value) {
                    $string[$key] = $this->__translateToBing($value);
                }
                $translation = implode(". ", $string);
            } else {
                $translation = $this->__translateToBing($stringToTranslate);
            }
        }
        $this->lastSourceString = $stringToTranslate;
        $output = rtrim($translation);
        return $output;
    }

}