<?php

require_once 'HLBrowser.php';

/**
 * Description of SearchSimulator
 *
 * @author TrungHieu
 */
class SearchSimulator
{

    private $_searchEngine;
    private $_keyword;
    private $_page;
    private $_serpHTML  = "";
    private $_actionURL = "";
    private $_input     = array();
    private $_logs      = array();

    public function __construct($searchEngine, $keyword, $page)
    {
        $this->_searchEngine = $searchEngine;
        $this->_keyword      = $keyword;
        $this->_page         = $page;

        $searchFrontPageHTML = $this->_getFrontPageHTML();
        if ($searchFrontPageHTML)
        {
            $this->_filterInputFields($searchFrontPageHTML);
            sleep(rand(1, 3)); // simulate the user input keyword time
            $this->_getSERPPage();
        }
    }

    public function output()
    {
        file_put_contents(ROOT_PATH . "SeachSimulator.request.log", implode("\n", $this->_logs));
        return $this->_serpHTML;
    }

    private function _getFrontPageHTML()
    {
        $appUrl              = curPageURL();
//	$proxier = new SERPProxier('get', $this->_searchEngine, array(), $appUrl, false);
        $proxier             = new HLBrowser($appUrl, FALSE, $this->_searchEngine);
        $requests            = $proxier->requestHistory();
        $this->_searchEngine = trim(end($requests), '/');
        $this->_logs[]       = "[FETCH FRONT PAGE]";
        $this->_logs         = array_merge($this->_logs, $proxier->requestHistory());
        return $proxier->getResponse();
    }

    private function _filterInputFields($html)
    {
        $html          = create_html($html);
        $searchForm    = $html->find('form[action=/search]', 0);
        $this->_logs[] = "[HTML]";
        $this->_logs[] = $html;
        if ($searchForm)
        {
            $this->_actionURL = $this->_searchEngine . $searchForm->action;
            $inputElements    = $searchForm->find("input");
            foreach ($inputElements as $ele)
            {
                if ($ele->name != 'btnI')
                    $this->_input[$ele->name] = $ele->value;
            }
            $this->_input['q'] = $this->_keyword;
        }
        $this->_logs[] = "[ANALYSE FORM ...]";
        $this->_logs[] = "[input ] " . http_build_query($this->_input);
        $this->_logs[] = "[action] " . $this->_actionURL;
    }

    private function _getSERPPage()
    {
        $appUrl          = curPageURL();
        $url             = "{$this->_actionURL}" . "?" . http_build_query($this->_input);
//	var_dump($url); die();
//	$proxier = new SERPProxier('get', $url, array(), $appUrl, false);
        $proxier         = new HLBrowser($appUrl, false, $url);
        $this->_logs[]   = "[FETCH FIRST SERP]";
        $this->_logs     = array_merge($this->_logs, $proxier->requestHistory());
        $html            = $proxier->getResponse();
        if ($this->_page == 1)
            $this->_serpHTML = $html;
        else
        {
            $htmlObject = create_html($html);
            $nav        = $htmlObject->find("#nav", 0);
            if ($nav)
            {
                $pageLink = $nav->find("td", $this->_page);
                if ($pageLink)
                {
                    $url             = $this->_getFormatedLink($pageLink->find('a', 0)->href);
                    sleep(rand(2, 3)); #simulate the user finding last link time;
//		    $proxier = new SERPProxier('get', $url, array(), $appUrl, false);
                    $proxier         = new HLBrowser($appUrl, false, $url);
                    $this->_logs[]   = "[FETCH TARGET PAGE]";
                    $this->_logs     = array_merge($this->_logs, $proxier->requestHistory());
                    $this->_serpHTML = $proxier->getResponse();
                }
            }
        }
    }

    private function _getFormatedLink($url)
    {
        $isAbsolute = false;
        if ((strpos($url, 0, 7) == 'http://') || (strpos($url, 0, 8) == 'https://'))
            $isAbsolute = true;
        if (!$isAbsolute)
            return $this->_searchEngine . $url;
        else
            return $url;
    }

}

?>
