<?php

ini_set('display_errors', 1);

ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');

$path = realpath('.') . '/shell/abstract.php';
if (file_exists('abstract.php')) {
    require_once 'abstract.php';
} else {
    require_once realpath('.') . '/shell/abstract.php';
}

/**
 * Class Ebizmarts_Recommender_AiShell
 */
class Ebizmarts_Recommender_AiShell extends Mage_Shell_Abstract
{
    private $_resources = array(
        'catalog',
        'usage',
    );

    private $_storeId   = null;
    private $_pageSize  = 1000;
    private $_debug     = false;
    private $_profiler  = false;
    /**
     * @var Instance of Mage::helper('bakerloo_restful')
     */
    private $_profilerLogger = null;
    private $_startPage = 2;

    public function getStoreId()
    {
        return $this->_storeId;
    }
    public function setStoreId($id = 0)
    {
        $this->_storeId = $id;
    }

    /**
     * Run script
     *
     */
    public function run()
    {
        $this->_debug    = (boolean)$this->getArg('trace');
        $this->_profiler = (boolean)$this->getArg('profiler');

        if ($this->_profiler) {
            $this->profilerStart();
        }

        $this->setStoreId((int)$this->getArg('storeid'));

        if (!$this->getStoreId()) {
            return $this->trace("Please provide a store ID. See help for more information.\n", true);
        }

        //Validate storeid
        try {
            $_store = Mage::app()->getStore($this->getStoreId());
            $_store->getId();

            Mage::app()->setCurrentStore($_store->getId());
        } catch (Exception $ex) {
            $this->trace("Store is not valid.\n", true);
            return -1;
        }


        //Generate SQLite and ZIP files.
        if ($this->getArg('cache')) {
            $this->generateCache();
            return -1;
        } elseif ($this->getArg('files')) {
            //@TODO: Check that provided pagesize is used even if its larger than safe limit.
            $pageSizeP = (int)$this->getArg('pagesize');
            if ($pageSizeP) {
                $this->_pageSize = $pageSizeP;
            }

            $startPage = (int)$this->getArg('startpage');
            if ($startPage) {
                $this->_startPage = $startPage;
            }

            $this->generateFiles($_store);
            return;
        } elseif ($this->getArg('try-again')) {
            $this->tryAgain();
            return;
        } else {
            $this->trace($this->usageHelp(), true);
            return;
        }
    }

    public function generateFiles($store)
    {
        $this->trace("++++++++++ Processing entities for Store: " . $store->getName() . " ++++++++++\n\n", true);

        $resource = $this->getArg('resource');

        if ($resource && in_array($resource, $this->_resources)) {
            $this->processResource($resource);
        } else {
            $this->trace($this->usageHelp(), true);
        }
    }

    private function processResource($resource)
    {

        $this->trace("********** >>> Processing {$resource} " . Mage::getModel('core/date')->gmtDate('Y-m-d H:i:s') . " **********\n\n");

        if ($this->_startPage === 2) {
            //Save first page
            $pageNumber = 1;

            $this->trace("START: Processing page {$pageNumber} " . Mage::getModel('core/date')->gmtDate('Y-m-d H:i:s') . "\n");

            Mage::getModel('Ebizmarts_Recommender_Model_Cron')
                ->catalog($this->_pageSize, $this->getStoreId());

            //Mage::helper('bakerloo_restful/pages')->storeData($resource, $data, $this->getStoreId(), $pageNumber);

            $this->trace("FINISH: Processing page {$pageNumber} " . Mage::getModel('core/date')->gmtDate('Y-m-d H:i:s') . "\n\n");


            if ($this->_profiler) {
                $this->profilerLog($resource, $pageNumber);
            }
        } else {
            $data = array();
            $data['next_page'] = $this->_startPage - 1;
            $pageNumber = $this->_startPage - 1;
        }

        //$ioAdapter = $this->getIo($this->getStoreId(), $resource, false);

        //Save from page 2 to page n
//        while (!is_null($data['next_page'])) {
//            $pageNumber++;
//
//            $this->trace("START: Processing page {$pageNumber} " . Mage::getModel('core/date')->gmtDate('Y-m-d H:i:s') . "\n");
//
//            $data = Mage::helper('bakerloo_restful/pages')->getData($resource, -1, $this->_pageSize, $pageNumber, $this->getStoreId());
//
//            $ioAdapter->write("page" . str_pad($pageNumber, 5, '0', STR_PAD_LEFT) . ".ser", serialize($data['page_data']));
//
//            $this->trace("FINISH: Processing page {$pageNumber} " . Mage::getModel('core/date')->gmtDate('Y-m-d H:i:s') . "\n\n");
//
//            if ($this->_profiler) {
//                $this->profilerLog($resource, $pageNumber);
//            }
//        }

        $this->trace("********** Finished {$resource} " . Mage::getModel('core/date')->gmtDate('Y-m-d H:i:s') . " <<< **********\n\n\n", true);
    }

    private function getIo($storeId, $resource, $reset)
    {
        return Mage::helper('bakerloo_restful/pages')->getIo($storeId, $resource, $reset);
    }

    public function trace($text, $force = false)
    {
        if (false === $force && false === $this->_debug) {
            return;
        }

        //@codingStandardsIgnoreLine
        echo sprintf('%s', $text);
    }

    protected function _reset()
    {
        $this->_io = null;
    }

    private function profilerStart()
    {
        return false;
        $this->_profilerLogger = Mage::helper('bakerloo_restful');
        $this->_profilerLogger->startprofiler();
    }

    private function profilerLog($resource, $pageNumber)
    {
        return false;
        $pageNumber = str_pad($pageNumber, 5, '0', STR_PAD_LEFT);

        $this->_profilerLogger->logprofiler($this->getStoreId(), $resource, 'page_'.$pageNumber);
    }

    /**
     * Generate extensive cache.
     *
     */
    public function generateCache()
    {
        $resource = $this->getArg('resource');

        $h = Mage::helper('bakerloo_restful/pages');

        $cacheable = $h->dbCacheResources();
        $dbTableName = $cacheable[$resource]['tableName'];

        $path = Mage::helper('bakerloo_restful/cli')->getPathToDb($this->getStoreId(), $resource, false);

        $sqliteName = "{$dbTableName}.sqlite";
        $zipiName   = "{$dbTableName}.zip";

        $basePath = $path . DS;
        $dbName   = $basePath . $sqliteName;
        $zipName  = $basePath . $zipiName;

        if (file_exists($dbName)) {
            @unlink($dbName);
        }

        $db = new SQLite3($dbName);

        $createTableSQL = $cacheable[$resource]['create'];

        $h->createCacheTables($createTableSQL, $db);

        $recordsCount = 0;

        //Read static pages.
        $io = $h->getIo($this->getStoreId(), $resource, false);

        $files = $h->getPagesToProcess($path);

        $actpage = 1;

        foreach ($files as $fileInfo) {
            $fileName = $fileInfo->getFilename();

            if ($fileName == "_pagedata.ser") {
                $fileData = unserialize($io->read($fileName));
                $this->trace("Processing {$fileData["totalrecords"]} $resource in #{$fileData["totalpages"]} pages.\n\n\n", true);
                continue;
            }

            $fileData = unserialize($io->read($fileName));

            if ($fileData === false) {
                $this->trace("Could not decode page: {$fileName}.", true);
                $db->close();
                $this->trace("Finished with error.\n\n\n", true);
                return;
            }

            if ('products' == $resource) {
                $recordsCount += $h->populateProducts($fileData, $db, $this->getStoreId());
            }

            if ('inventory' == $resource) {
                $recordsCount += $h->populateProductsInventory($fileData, $db);
            }

            if ('customers' == $resource) {
                $recordsCount += $h->populateCustomers($fileData, $db);
            }

            $processed = $recordsCount;
            $this->trace("Processed {$processed} records.\n\n\n", true);

            $actpage++;

            unset($fileData);
        }

        $db->close();

        if ($recordsCount == 0) {
            $this->trace("No pages found to create DB. Resource: {$resource}.\n\n\n", true);
        }

        $insertedRows = $h->countRows($dbName, $dbTableName);
        if ($insertedRows !== $recordsCount) {
            $this->trace("Record count dont match #{$insertedRows} vs #{$recordsCount} \n Resource: {$resource}.\n\n", true);
        }


        //Create ZIP file.
        if (file_exists($zipName)) {
            @unlink($zipName);
        }

        $zip = new ZipArchive;
        $zipOpen = $zip->open($zipName, ZipArchive::CREATE);
        if ($zipOpen === true) {
            $zip->addFile($dbName, $sqliteName);
            $zip->close();

            $this->trace("**********Created ZIP OK.**********\n\n", true);
        } else {
            $this->trace("ERROR: Could not create ZIP Error: `{$zipOpen}`.**********\n\n\n", true);
        }


        $this->trace("********** Finished {$resource} " . Mage::getModel('core/date')->gmtDate('Y-m-d H:i:s') . " <<< **********\n\n\n", true);
    }

    /**
     * Retrieve Usage Help Message
     *
     */
    public function usageHelp()
    {
        return <<<USAGE
Usage:  php pos.php -- [options]

  --files <int>          Generate static files
  --cache <int>          Create cache (SQLite and ZIP)
  --try-again <int>      Place an order
  --storeid <int>        Specify Store ID
  --order-id             Specify Order ID
  --pagesize <int>       Set page size, default is 200
  --resource <string>    Generate specified entity, for example customers
  --trace <int>          Show information about activity on screen
  --profiler <int>       Profile generation
  --startpage <int>      Start export on a given page > 1
  all                    Generate all entities: products, categories, customers, inventory
  help                   This help

USAGE;
    }


    public function loguear($data)
    {
        Mage::log($data, null, "Ai_Shell.log");
    }
}

$shell = new Ebizmarts_Recommender_AiShell();
$shell->run();