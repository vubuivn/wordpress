<?php
error_reporting(E_ALL);
define('URL_FIELD_NAME', 'serp-proxier-url');
define('URL_IMAGE_NAME', 'serp-proxier-image');

define('ROOT_PATH', dirname(__FILE__) . DIRECTORY_SEPARATOR);

require_once 'src/helpers.php';
require_once 'src/HLBrowser.php';
require_once 'src/SearchSimulator.php';


$appUrl = curPageURL();
if (isset($_REQUEST['se']) && isset($_REQUEST['kw']) && isset($_REQUEST['pg']))
{
    $simulator = new SearchSimulator(urldecode($_REQUEST['se']), urldecode($_REQUEST['kw']), $_REQUEST['pg']);
    echo $simulator->output();
} else
{
    $browser = new HLBrowser($appUrl);
    $browser->output();
}
exit();