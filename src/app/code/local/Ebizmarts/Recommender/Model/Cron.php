<?php

class Ebizmarts_Recommender_Model_Cron
{
    private static $linecount = null;

    // This function implements a generator to load individual lines of a large file
    private function getLines($file) {
        $f = fopen($file, 'r');

        // read each line of the file without loading the whole file to memory
        while ($line = fgets($f)) {
            yield $line;
        }
    }

    private function getFilePath($fileName)
    {
        $filePath = BP . DS . 'var' . DS . 'ai';

        if (file_exists($filePath) === false) {
            mkdir($filePath);
        }
        return $filePath  . DS . $fileName . '.csv';
    }

    private function getLineCount($file, $pageSize)
    {
        if ($this->linecount === null) {
            if (file_exists($file)) {
                $lineCount = iterator_count($this->getLines($file)) + $pageSize; // the number of lines in the file
                $this->linecount = $lineCount;
            }
        }
        else {
            $lineCount = $this->linecount;
        }

        return $lineCount;
    }

    private function writeToFile($writeData, $file, $pageNumber, $totalPages)
    {
        $this->linecount += count($writeData);

        $csvWriter = new \Ebizmarts\Recommendations\RecommenderFile(
            new \Ebizmarts\Recommendations\Filesystem\CsvWriter($file)
        );
        $csvWriter->writeToCsv($writeData);

        $pageN = null;

        $nextPage = $pageNumber + 1;
        if ($nextPage <= $totalPages) {
            $pageN = $nextPage;
        }

        unset($csvWriter);
        unset($writeData);
        unset($orderItems);
        unset($date);

        return ['next_page' => $pageN];
    }

    public function usage($pageSize, $storeId)
    {
        $file = $this->getFilePath("usage");

        $orderItems = Mage::getModel('sales/order_item')
            ->getCollection()
            ->addAttributeToSelect("order_id")
            ->addAttributeToSelect("sku")
            ->addAttributeToFilter('product_type', ['neq' => 'grouped']);
        $orderItems
            ->getSelect()
            ->joinLeft(
                ["o" => $orderItems->getTable('sales/order')],
                "main_table.order_id = o.entity_id",
                ['customer_email', 'order_created_at' => 'o.created_at']
            );

        $totalRecords = $orderItems->getSize();

        $orderItems->getSelect()->order(['order_id ASC']);

        $lineCount = $this->getLineCount($file, $pageSize);

        $totalPages = ceil($totalRecords/$pageSize);
        $pageNumber = $lineCount/$pageSize;

        if($pageNumber > $totalPages) {
            return false;
        }

        $orderItems->getSelect()->limitPage($pageNumber, $pageSize);

        $writeData = [];

        $date = Mage::getModel('core/date');

        while ($orderItem = $orderItems->fetchItem()) {
            $usageItem = new \Ebizmarts\Recommendations\Data\V4_0\UsageItem();
            $usageItem->setUserId(md5($orderItem->getCustomerEmail()));
            $usageItem->setItemId($orderItem->getSku());
            $usageItem->setTime(
                $date->date("Y-m-d\TH:i:s", $date->gmtTimestamp($orderItem->getOrderCreatedAt()))
            );
            $usageItem->setEvent("Purchase");

            array_push($writeData, $usageItem);
            unset($usageItem);
        }

        return $this->writeToFile($writeData, $file, $pageNumber, $totalPages);
    }

    public function catalog($pageSize, $storeId)
    {
        $file = $this->getFilePath("catalog");

        $products = Mage::getModel('catalog/product')
            ->getCollection()
            ->addStoreFilter()
            ->addAttributeToSelect('sku');

        $products->addStoreFilter($storeId);

        $products->joinAttribute(
            'description',
            'catalog_product/description',
            'entity_id',
            null,
            'inner',
            $storeId
        );

        $featureAttributes = Mage::getStoreConfig('catalog/ebizmarts_price_inteligence_features/attributes', $storeId);
        $featureAttributesArray = explode(',', $featureAttributes);

        $attributesCount = count($featureAttributesArray);
        for ($i = 0; $i < $attributesCount; $i++) {
            $products->joinAttribute(
                $featureAttributesArray[$i],
                'catalog_product/' . $featureAttributesArray[$i],
                'entity_id',
                null,
                'inner',
                $storeId
            );
        }

        $products->addCategoryIds();

        $totalRecords = $products->getSize();

        if (0 === $totalRecords) {
            Mage::throwException("No products found.");
        }

        $products->getSelect()->order(['sku ASC']);

        $lineCount = $this->getLineCount($file, $pageSize);

        $totalPages = ceil($totalRecords/$pageSize);
        $pageNumber = $lineCount/$pageSize;

        if($pageNumber > $totalPages) {
            return false;
        }

        $products->getSelect()->limitPage($pageNumber, $pageSize);

        $writeData = [];

        /** @var Mage_Catalog_Model_Product $product */

        while ($product = $products->fetchItem()) {
            if (empty($product->getSku()) or $product->getTypeId() == 'grouped') {
                continue;
            }
            $catalogItem = new \Ebizmarts\Recommendations\Data\V4_0\CatalogItem();
            $catalogItem->setId($product->getSku());
            $catalogItem->setName($product->getName());
            $catalogItem->setCategory(implode("-", $product->getCategoryIds()));
            $catalogItem->setDescription(preg_replace("/[\n\r]/"," ", strip_tags(html_entity_decode($product->getDescription()))));

            /*$position = $product->getPosition();
            if (empty($position)) {
                $position = $product->getPositionAmezaga();
                if (empty($position)) {
                    $position = $product->getPositionConstitution();
                }
            }*/

            $features = [];

            for ($i = 0; $i < $attributesCount; $i++) {
                $features []= ucwords($featureAttributesArray[$i]) . '=' . $featureAttributesArray[$i];
            }

            //$features []= 'Position=' . $position;
            //$features []= 'PromoProduct=' . (int)$product->getIsPromoProduct();

            $catalogItem->setFeaturesList(implode(", ", $features));

            array_push($writeData, $catalogItem);
            unset($catalogItem);
        }

        return $this->writeToFile($writeData, $file, $pageNumber, $totalPages);
    }
}