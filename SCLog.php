<?php
/**
 * SCLog is a logging class that collects output from several endpoints and organizes them under a single database
 * grouping for a specific Product Extension (customization). The idea is to make it easy to see what Product Extensions
 * exist on a site, easy to configure, and how they are performing regardless of what the pieces are; Add-ins, CP controllers,
 * CPMs, Custom Scripts, etc.
 *
 * SCLog was created by Service Ventures LLC. (servicecloudedge.com) and is being released under an MIT License.
 * 
 * For more features including write to file, a web based log file browser, additional logging options and integration
 * support check out SCLog PRO at servicecloudedge.com
 *
 * @copyright   Service Ventures LLC - 2015
 * @author      Service Ventures LLC <info@servicecloudedge.com>
 * @license     MIT
 * @package     ServiceVentures
 * @version     1.1
 */

namespace Custom\Libraries;

use RightNow\Connect\v1_2 as RNCPHP;
use Exception;

require_once get_cfg_var("doc_root") . "/ConnectPHP/Connect_init.php";

/**
 * Constants for Extension Group and Signature hash.
 */
define('DEFAULT_EXT', 'SCLog');
define('DEFAULT_SIG', '92acaed5cb6dc95783ea1d0b194347c542017bfc');

class SCLog {
    protected $start;
    protected $extSignature;
    protected $extName;
    protected $extObject;
    protected $extID;
    protected $callingFile;
    public $clickXRefs = null;
    protected $functionServed;
    protected $dir = '/tmp/SCLog';
    protected $extDir;
    protected $extConfigs;
    public $logThreshold;
    public $traceCache = array();
    private $logFile;

    const TRACE_CACHE_SIZE = 20;
	const MAX_STRING_BYTES = 1048576;
    protected $host = '';
    protected $hostIP = '';
    protected $processID = 0;
    protected $myUID = '';

    const FATAL = 1;
    const ERROR = 2;
    const WARNING = 3;
    const NOTICE = 4;
    const DEBUG = 5;
    const CLICK = 6;

    protected $headers = array(
        'Time',
        'File',
        'Function',
        'MsgType',
        'Message',
        'Host',
        'PID',
        'Detail',
        'UID',
        'TimeElapsed',
        'Peak',
        'xRefs'
    );

    /**
     * Instantiates a new instance of the logging object
     *
     * @param string $extensionName Optional - Name of the extension calling the log, defaults to "SCLog", this is used to group calls from the same product extension or customization together
     * @param string $extensionSignature Optional - Signature of the extension, if a valid scProductExtension signature is passed it will use the ExtConfiguration field from that record to configure SCLog, defaults to signature for SCLog
     * @param int $scriptStartTime Optional - Unix timestamp used to specify a different script start time, useful for logging script total execution time, defaults to null (current timestamp)
     * @param int $logThreshold Optional - Sets the minimum error level of logging, defaults to 5 (debug)
     * @param string $functionServed Optional - Description for use of logging
     * @param string $callingfile Optional - Source of logging call
     * @throws \Exception
     */
    function __construct($extensionName = DEFAULT_EXT, $extensionSignature = null, $scriptStartTime = null, $logThreshold = 5, $functionServed = 'Not Specified', $callingfile = 'Not Specified') {	
        $this->start = $scriptStartTime > 0 ? $scriptStartTime : microtime(true);
        $this->extName = $extensionName;

        if (strlen($extensionSignature) == 40) {
            $this->extSignature = $extensionSignature;
        } else {
            $this->extSignature = DEFAULT_SIG;
        }
        if (is_numeric($logThreshold))
        {
	        $this->logThreshold = $logThreshold;
        }
        else
        {
	        throw new \Exception('Invalid Log Level');
        }
        $this->callingFile = $callingfile;
        $this->functionServed = $functionServed;
        set_exception_handler(array('self', 'exception_handler'));
        $this->initializeLogger();
    }

    function exception_handler($exception) {
        echo "Uncaught exception: ", $exception->getMessage(), "\n";
    }

    function __destruct() {
        /*******************************************************************************************************
         **** Log the end of the lifecycle so we have the total run time of the script                      ****
         ******************************************************************************************************/
        if (!is_null($this->extConfigs["logClicks"]) && $this->extConfigs["logClicks"] == '1') {
            $this->click('Execution Completed');
        }
    }
   

    function fatal($message, $detail = null, $incident = null, $contact = null, $source = null, $function = null, $timeElapsed = null, $host = null, $processID = 0) {
        $this::log($message, $detail, $incident, $contact, $source, $function, $timeElapsed, self::FATAL, $host, $processID);
    }

    function click($message, $detail = null, $incident = null, $contact = null, $source = null, $function = null, $timeElapsed = null, $host = null, $processID = 0) {
        $this::log($message, $detail, $incident, $contact, $source, $function, $timeElapsed, self::CLICK, $host, $processID);
    }

    function debug($message, $detail = null, $incident = null, $contact = null, $source = null, $function = null, $timeElapsed = null, $host = null, $processID = 0) {
        $this::log($message, $detail, $incident, $contact, $source, $function, $timeElapsed, self::DEBUG, $host, $processID);
    }

    function error($message, $detail = null, $incident = null, $contact = null, $source = null, $function = null, $timeElapsed = null, $host = null, $processID = 0) {
        $this::log($message, $detail, $incident, $contact, $source, $function, $timeElapsed, self::ERROR, $host, $processID);
    }

    function warning($message, $detail = null, $incident = null, $contact = null, $source = null, $function = null, $timeElapsed = null, $host = null, $processID = 0) {
        $this::log($message, $detail, $incident, $contact, $source, $function, $timeElapsed, self::WARNING, $host, $processID);
    }

    function notice($message, $detail = null, $incident = null, $contact = null, $source = null, $function = null, $timeElapsed = null, $host = null, $processID = 0) {
        $this::log($message, $detail, $incident, $contact, $source, $function, $timeElapsed, self::NOTICE, $host, $processID);
    }

    /**
     * Commit information to log
     *
     * @param string $message The message to be logged
     * @param string $detail Detail of log message
     * @param RNCPHP\Incident $incident Oracle Service Cloud Incident to be associated to log record
     * @param RNCPHP\Contact $contact Oracle Service Cloud Contact object to be associate to log record
     * @param string $file Sets the file value of the log entry
     * @param string $function Sets the function value of the log entry
     * @param string $timeElapsed Set time elapsed for log entry, useful for benchmarking
     * @param int $messageType The severity level of the log a value from 1 (Fatal) to 6 (Click) - defaults to log threshold
     * @param string $host Sets the host name of the log entry - defaults to the value of $_SERVER['SERVER_NAME']
     * @param int $processID Sets the value of the processID - defaults to the value of getmypid()
     */
	function log($message, $detail = null, $incident = null, $contact = null, $file = null, $function = null, $timeElapsed = 0, $messageType = 0, $host = null, $processID = 0)
	{
        try {
            if (strlen($message) > 255){
	            $message = substr($message, 0, 255);
            }
            if (!is_null($detail)){
	            $detail = self::_trimMaxLengthString($detail);
            }
            if (strlen($file) > 255) {
                $file = substr($file, -254);
            }

            if (strlen($this->function) > 255) {
                $function = substr($function, 0, 255);
            }
            
            if (!is_null($incident) || !is_null($contact))
            {
	            $xRefArray = array($incident, $contact);	            
            }

            $out = array();
            foreach ($this->headers as $header) {
                switch ($header) {
	                case 'Time':
	                		$out['Time'] = date_format(new \DateTime(), 'Y-m-d H:i:s');
	                	break;
                    case 'File':
                    	if (!is_null($file))
	                        $out['File'] = $file;
	                    else
	                    	$out['File'] = $this->callingFile;
                        break;

                    case 'Function':
                    	if (!is_null($function))
	                        $out['Function'] = $function;
	                    else
	                    	$out['Function'] = $this->functionServed;
                        break;

                    case 'TimeElapsed':
                    	if ($timeElapsed != 0)
	                        $out['TimeElapsed'] = $timeElapsed;
	                    else
	                    	$out['TimeElapsed'] = $this->timeElapsed();
                        break;

                    case 'MsgType':
                    	if ($messageType != 0)
	                        $out['MsgType'] = $messageType;
	                    else
	                    	$out['MsgType'] = $this->logThreshold;
                        break;
                       
                    case 'Message':
                        $out['Message'] = $message;
                        break;

					case 'Detail':
						if (!is_null($detail))
							$out['Detail'] = $detail;
						else
							$out['Detail'] = $message;
						break;
						
                    case 'Host':
                    	if (!is_null($host))
	                        $out['Host'] = $host;
	                    else
	                    	$out['Host'] = $this->host;
                        break;

                    case 'PID':
                    	if ($processID != 0)
	                        $out['PID'] = $processID;
	                    else
	                    	$out['PID'] = $this->processID;
                        break;

                    case 'UID':
                        $out['UID'] = $this->myUID;
                        break;

                    case 'Memory':
                        $out['Memory'] = $this->formatBytes(memory_get_usage(true));
                        break;

                    case 'Peak':
                        $out['Peak'] = intval(memory_get_peak_usage(true) / 1024);
                        break;

                    case 'xRefs':
                        $out["xRefs"] = $xRefArray;
                        break;
                    
                }
            }

            if (count($this->traceCache) < 50) {
                $this->traceCache[] = $out;
            } else {
                array_shift($this->traceCache);
                $this->traceCache[] = $out;
            }

            if ($out['MsgType'] <= intval($this->extConfigs["logThreshold"])) {
                if (!is_null($this->extConfigs["logtoDatabase"]) && $this->extConfigs["logtoDatabase"] == '1') {
                    $output = array($out);
                    $this->logtoDatabase($output, false);
                }
            }
        } catch (\Exception $e) {
            echo('logging failed with :' . $e);
        }
    }

    protected function logtoDatabase(&$array) {
	    if (sizeof($array) == 0)
	    {
		    return;
	    }
        try {
            foreach ($array as $key => $row) {
                $logObj = new RNCPHP\SvcVentures\scLog();
                
                $logObj->scProductExtension = $this->extObject;
                $logObj->Message = $row['Message'];
                $logObj->Detail = $row['Detail'];
                $logObj->MsgType = intval($row['MsgType']);
                $logObj->Host = $row['Host'];
                $logObj->Function = $row['Function'];
                $logObj->File = $row['File'];
                $logObj->ProcessID = $row['PID'];
                $logObj->PeakMemory = intval($row['Peak']);
                
                if ($row['TimeElapsed'] !== null) {
                    $logObj->TimeElapsed = intval($row['TimeElapsed']);
                }
                $logObj->save();

                if (!is_null($row['xRefs'])) {
                    $xRefArray = $row['xRefs'];
                    $xrefObj = new RNCPHP\SvcVentures\scLogXref();
                    $xrefObj->scLog = $logObj;
                    if ($xRefArray[0] !== null) {
                        $xrefObj->Incident = $xRefArray[0];
                    }
                    if ($xRefArray[1] !== null) {
                        $xrefObj->Contact = $xRefArray[1];
                    }
                    $xrefObj->save();
                }
            }
            return true;
        } catch (Exception $e) {

            echo('SCLog Exception:' . $e);
        }
    }

    protected function initializeLogger() {
        /********************************************************************************************************
         **** Look up Product Extension Name by Extension Signature hash so we can group log entries under it ****
         *********************************************************************************************************/
        try {
            initConnectAPI();           
            //default config if there is none
            $jsonPackage = '
                    {
                        "logtoFile": "0",
                        "logtoDatabase": "1",
                        "logThreshold": "5",
                        "logClicks": "0"
                    }';
            $this->extConfigs = json_decode($jsonPackage, true);
            
            //Setup common log row values            
            $this->host = gethostname();
            $this->hostIP = sprintf("%s:%s", $_SERVER['SERVER_ADDR'], $_SERVER['SERVER_PORT']);
            $this->processID = getmypid();
            $this->myUID = getmyuid();

            if ($this->extSignature == DEFAULT_SIG) //not logging for an extension
            {
	            $extDirString = self::_sanitizeFilename($this->extName);
	            $this->extObject = 0;
	            $this->extDir = $this->dir . '/' . $extDirString;
                $this->logFile = sprintf("%s/%s.csv", $this->extDir, date("Y-m-d"));	            
	            return;	            		            
            }

            $scExtQry = sprintf("SELECT * FROM SvcVentures.scProductExtension where Signature like '%s'", $this->extSignature);
            $extRows = RNCPHP\ROQL::query($scExtQry)->next();
            if ($extRows->count() > 0) {
                $queryRow = $extRows->next();

                $extDirString = self::_sanitizeFilename($queryRow['ExtensionName']);
                
                //Setup log paths

                $this->extID = intval($queryRow["ID"]);
                $this->extName = $queryRow["ExtentionName"];
                $this->extDir = $this->dir . '/' . $extDirString;
                $this->logFile = sprintf("%s/%s.csv", $this->extDir, date("Y-m-d"));

                $this->extObject = RNCPHP\SvcVentures\scProductExtension::fetch($this->extID);
                //Load Extension Configuration elements from the database if possible
                if (!empty($queryRow['ExtConfiguration']))
	                $this->extConfigs = json_decode($queryRow["ExtConfiguration"], true);

                if (!is_array($this->extConfigs)) {
                    // config is empty or malformed: use defaults
                    $this->extConfigs = json_decode($jsonPackage, true);
                }

                if (is_null($this->extConfigs["logtoDatabase"])) {
                    $this->extConfigs["logtoDatabase"] = '1';
                }

                if (is_null($this->extConfigs["logtoFile"])) {
                    $this->extConfigs["logtoFile"] = '1';
                }

                // log everything by default
                if (is_null($this->extConfigs["logThreshold"])) {
                    $this->extConfigs["logThreshold"] = '5';
                }

                // log clicks by default
                if (is_null($this->extConfigs["logClicks"])) {
                    $this->extConfigs["logClicks"] = '0';
                }
            } else {
                // create entry for this customization
                $this->extObject = new RNCPHP\SvcVentures\scProductExtension();
                $this->extObject->ExtensionName = $this->extName;
				
                $this->extObject->Description = 'Not Specified';
                $this->extObject->Authors = 'Not Specified';
                $this->extObject->Signature = $this->extSignature;

                $this->extObject->ExtConfiguration = json_encode($this->extConfigs);

                $this->extObject->save();

                $this->extDir = $this->dir . '/General';
                $this->logFile = sprintf("%s/%s.csv", $this->extDir, date("Y-m-d"));
            }
        } catch (\Exception $e) {
            echo ($e);
        }

        /**********************************************************************************************
         **** Create directories for the scLog class and the specific Product Extension in temp    ****
         **********************************************************************************************/
        if (!file_exists($this->dir)) { // check to see if we have a base log directory or make one
            if (!mkdir($this->dir)) {    // if not make it
                throw new \Exception(sprintf("Failed to create the root log directory '%s'", $this->dir));
                exit();
            }
        }

        if (!is_dir($this->dir)) { // confim that we actually have a direcoty
            throw new \Exception(sprintf("Failed to create the root log directory '%s'", $this->dir));
            exit();
        }

        if (!file_exists($this->extDir)) { // make a SC Extension specific log directory
            if (!mkdir($this->extDir)) {    // if not make it
                throw new \Exception(sprintf("Failed to create the SC Extension specific log directory '%s'", $this->extDir));
                exit();
            }
        }

        if (!file_exists($this->logFile)) { // make a SC Extension specific log directory
            // Create log file
            $this->writeCsv($this->headers);
            $server = "";
            foreach ($_SERVER as $idx => $val) {
                $server .= sprintf("%s: %s\n", $idx, $val);
            }
        }
    }

    protected function timeElapsed() {
        $timeElapsed = microtime(true) - $this->start;
        $timeElapsed = intval($timeElapsed * 1000);
        return $timeElapsed;
    }

	private function _trimMaxLengthString($value)
	{
		if (utf8_decode($value) > MAX_STRING_BYTES)
		{
			return utf8_encode(substr(utf8_decode($value), 0, 255));
		}
		else
		{
			return $value;
		}
	}

	private function _sanitizeFilename($value)
	{		
        $spChars = array("/", "\\", '=', '?', '[', ']', '<', '>', ':', ';', ',', "'", "\'", '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}');
        $output = str_replace($spChars, '', $value);
        $output = preg_replace('/[\s-]+/', '-', $output);
        return trim($output, '.-_');
	}
}