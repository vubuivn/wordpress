<?php

require_once 'helpers.php';
require_once 'htmldom.lib.php';

/**
 * Description of HLBrowser
 *
 * @author TrungHieu
 */
class HLBrowser
{

    private static $_userAgent        = "Mozilla/5.0 (Windows NT 6.3; WOW64; rv:32.0) Gecko/20100101 Firefox/32.0";
    private static $_encoding         = "gzip, deflate";
    private static $_cookieFile       = "hlb.cookie.log";
    private static $_headerFile       = "hlb.header.log";
    private static $_stderrFile       = "hlb.stderr.log";
    private static $_redirectSelector = "meta[http-equiv='refresh']";
    private static $_isDebugging      = true;
    private static $_urlFieldName     = 'hlb-url';
    private static $_logFile          = 'hlb.log';
    private $_contentType             = '';
    private $_isPostRequest           = false;
    private $_targetUrl               = "";
    private $_postData                = "";
    private $_appUrl                  = "";
    private $_curl                    = null;
    private $_responseBody            = "";
    private $_requestedUrls           = array();
    private $_isProxy                 = true;
    private $_targetBaseUrl;
    private $_logLines                = array();
    private $_header                  = array();

    public function __construct($appUrl, $isProxy = true, $targetUrl = null)
    {
        $this->_appUrl  = $appUrl;
        $this->_isProxy = $isProxy;
        $this->_curl    = curl_init();

        HLBrowser::$_cookieFile = ROOT_PATH . HLBrowser::$_cookieFile;
        HLBrowser::$_logFile    = ROOT_PATH . HLBrowser::$_logFile;
        HLBrowser::$_headerFile = ROOT_PATH . HLBrowser::$_headerFile;


        if ($targetUrl != null)
            $this->_setTargetUrl($targetUrl);
        $this->_detectRequestType();
        $this->_detectTargetUrl();
        $this->_request();
    }

    public function output()
    {
        $this->_dumpLog();
        header("Content-Type: {$this->_contentType}");
        echo $this->getResponse();
    }

    public function getResponse()
    {
        $body = $this->_responseBody;
        if ($this->_isHTML())
        {

            $body = $this->_stripScripts($body);
            if ($this->_isProxy || $this->_isCaptchaPage())
                $body = $this->_proxifyLinks($body);
        }
        return $body;
    }

    public function requestHistory()
    {
        return $this->_requestedUrls;
    }

    private function _detectRequestType()
    {
        $this->_isPostRequest = strtolower($_SERVER['REQUEST_METHOD']) === 'post';
        if ($this->_isPostRequest)
            $this->_postData      = $_POST;
        $this->_log("Request Type: {$_SERVER['REQUEST_METHOD']}");
        if (!empty($this->_postData))
            $this->_log("POST: " . http_build_query($this->_postData));
    }

    private function _detectTargetUrl()
    {
        if ($this->_targetUrl != null)
            return;
        if (isset($_REQUEST[HLBrowser::$_urlFieldName]))
            $this->_setTargetUrl(urldecode($_REQUEST[HLBrowser::$_urlFieldName]));
        else
        {
            $this->_setTargetUrl('https://www.google.com.vn');
        }
    }

    private function _setTargetUrl($url)
    {
        $queryString = $_GET;
        if (isset($_GET[HLBrowser::$_urlFieldName]))
        {
            unset($queryString[HLBrowser::$_urlFieldName]);
        }
        if (isset($_GET['se']))
        {
            unset($queryString['se']);
        }
        if (isset($_GET['kw']))
        {
            unset($queryString['kw']);
        }
        if (isset($_GET['pg']))
        {
            unset($queryString['pg']);
        }
        $queries = parse_url($url);
        if (isset($queries['query']))
        {
            $qs = explode('&', $queries['query']);
            foreach ($qs as $q)
            {
                $args                  = explode("=", $q);
                if (!isset($queryString[$args[0]]))
                    $queryString[$args[0]] = urldecode($args[1]);
            }
        }
        $queryPosition = strpos($url, '?');
        if ($queryPosition !== FALSE)
        {
            $this->_targetBaseUrl = substr($url, 0, $queryPosition);
        }
        else
        {
            $this->_targetBaseUrl = $url;
        }
        $this->_targetUrl = $this->_targetBaseUrl . (empty($queryString) ? "" : ( "?" . http_build_query($queryString)));
        $this->_log("URL     : {$this->_targetUrl}");
        $this->_log("URL Base: {$this->_targetBaseUrl}");
        $this->_log("GET     : " . http_build_query($queryString));
    }

    private function _request()
    {
        // reset response header array
        $this->_header          = array();
        $this->_requestedUrls[] = $this->_targetUrl;

        // prepare curl options
        curl_setopt_array($this->_curl, array(
            CURLOPT_URL            => $this->_targetUrl,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => static::$_userAgent,
            CURLOPT_ENCODING       => static::$_encoding,
            CURLOPT_COOKIEFILE     => static::$_cookieFile,
            CURLOPT_COOKIEJAR      => static::$_cookieFile,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => array(
                "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                "Accept-Language: en-US,en;q=0.5",
                "Connection: keep-alive",
                "Keep-Alive: 300",
            ),
            CURLOPT_HEADER         => false,
            CURLOPT_AUTOREFERER    => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => array($this, '_headerCallback'),
        ));

        if ($this->_isPostRequest)
        {
            curl_setopt($this->_curl, CURLOPT_POST, true);
            curl_setopt($this->_curl, CURLOPT_POSTFIELDS, http_build_query($this->_postData));
        }
        /* for debugging */
        if (static::$_isDebugging)
        {
            $stderrFile = fopen(static::$_stderrFile, "w");
            curl_setopt($this->_curl, CURLOPT_VERBOSE, true);
            curl_setopt($this->_curl, CURLOPT_STDERR, $stderrFile);
        }

        $result              = curl_exec($this->_curl);
        $this->_responseBody = html_entity_decode($result);
        $status              = curl_getinfo($this->_curl);
        /* for debugging */
        if (static::$_isDebugging)
        {
            fclose($stderrFile);
        }

        if (isset($status['redirect_url']) && $status['redirect_url'])
        {
            $this->_targetUrl = $status['redirect_url'];
            $this->_request();
        }
        else if ($this->_isHTML())
        {
            $redirectUrl = $this->_findRedirectURL($this->_responseBody);
            if ($redirectUrl)
            {
                $this->_targetUrl = $redirectUrl;
                $this->_request();
            }
        }
    }

    public function _headerCallback($ch, $headerLine)
    {
        $headerSegments = explode(':', trim($headerLine));
        if (count($headerSegments) > 1 && $headerSegments[0] == 'Content-Type')
        {
            $this->_contentType = trim($headerSegments[1]);
            $this->_log("Content Type: {$headerSegments[1]}");
        }
        $this->_header[] = trim($headerLine);
        return strlen($headerLine);
    }

    private function _stripScripts($html)
    {
        return preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);
    }

    private function _findRedirectURL($html)
    {
        $html     = create_html($html);
        if (!$html)
            return false;
        $elements = $html->find(static::$_redirectSelector);
        if (!empty($elements))
        {
            $segments = explode(";", $elements[0]->content);
            $segments = explode("=", $segments[1], 2);
            return $this->_createUrlFromRelative($segments[1]);
        }
        return false;
    }

    private function _proxifyLinks($html)
    {
        $html = create_html($html);

        // LINKS
        $linkElements = $html->find("a");
        foreach ($linkElements as $ele)
        {
            $url       = $ele->href;
            $ele->href = $this->_formatUrl($url);
        }

        // FORMS
        $linkElements = $html->find("form");
        foreach ($linkElements as $ele)
        {
            $url            = $ele->action;
            $ele->action    = $this->_appUrl;
//	    $ele->action = $this->_formatUrl($url);
            $inputName      = HLBrowser::$_urlFieldName;
            $inputValue     = $this->_formatUrl($url, true);
            $input          = "<input name='{$inputName}' type='hidden' value='{$inputValue}' />";
            $ele->outertext = $ele->makeup() . $ele->innertext . $input . '</form>';
        }

        // IMAGES
        $linkElements = $html->find("img");
        foreach ($linkElements as $ele)
        {
            $url      = $ele->src;
            $ele->src = $this->_formatUrl($url);
        }

        return $html->save();
    }

    private function _formatUrl($originalUrl, $isForm = false)
    {
        $field = HLBrowser::$_urlFieldName;
        if ($this->_isAbsoluteUrl($originalUrl))
        {
            return $isForm ? urlencode($originalUrl) : ($this->_appUrl . "?{$field}=" . urlencode($originalUrl));
        }
        else
        {
            $absUrl = $this->_createUrlFromRelative($originalUrl);
            return $isForm ? urlencode($absUrl) : $this->_appUrl . "?{$field}=" . urlencode($absUrl);
        }
    }

    private function _isAbsoluteUrl($url)
    {
        if (substr($url, 0, 7) == 'http://')
            return true;
        else if (substr($url, 0, 8) == 'https://')
            return true;
        else if (substr($url, 0, 2) == '//')
            return true;
        return false;
    }

    private function _isCaptchaPage()
    {
        $pattern = "sorry/IndexRedirect";
        return strpos($this->_targetUrl, $pattern) !== FALSE;
    }

    private function _createUrlFromRelative($path)
    {
        if (substr($path, 0, 7) == 'http://' || substr($path, 0, 8) == 'https://' || substr($path, 0, 2) == '//')
        {
            return $path;
        }
        $segments = parse_url($this->_targetUrl);
        $urlArray = array($segments['scheme'], "://", $segments['host']);
        if (isset($segments['port']))
        {
            $urlArray[] = ":";
            $urlArray[] = $segments['port'];
        }
        $urlArray[] = '/' . $path;
        return implode("", $urlArray);
    }

    private function _log($line)
    {
        $this->_logLines[] = $line;
    }

    private function _dumpLog()
    {
        if ($this->_isHTML())
        {
            file_put_contents(HLBrowser::$_logFile, implode("\n", $this->_logLines));
            file_put_contents(HLBrowser::$_headerFile, implode("\n", $this->_header));
        }
    }

    private function _isHTML()
    {
        return strpos($this->_contentType, 'text/html') !== FALSE;
    }

    public function __destruct()
    {
        curl_close($this->_curl);
    }

}

?>
