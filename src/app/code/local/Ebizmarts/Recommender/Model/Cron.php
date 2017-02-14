<?php

class Ebizmarts_Recommender_Model_Cron
{
    // This function implements a generator to load individual lines of a large file
    private function getLines($file) {
        $f = fopen($file, 'r');

        // read each line of the file without loading the whole file to memory
        while ($line = fgets($f)) {
            yield $line;
        }
    }

    public function usage()
    {
        
    }

    public function catalog($pageSize, $storeId)
    {
        $filePath = BP . DS . 'var' . DS . 'ai';

        if (file_exists($filePath) === false) {
            mkdir($filePath);
        }

        $file = $filePath  . DS . 'catalog.csv';

        $lineCount = $pageSize;

        $products = Mage::getModel('catalog/product')
            ->getCollection()
            ->addStoreFilter()
            ->addAttributeToSelect('sku');

        $products->addStoreFilter($storeId);
        $products->joinAttribute(
            'name',
            'catalog_product/name',
            'entity_id',
            null,
            'inner',
            $storeId
        );
        $products->joinAttribute(
            'short_description',
            'catalog_product/short_description',
            'entity_id',
            null,
            'inner',
            $storeId
        );
        $products->joinAttribute(
            'description',
            'catalog_product/description',
            'entity_id',
            null,
            'inner',
            $storeId
        );
        $products->joinAttribute(
            'price',
            'catalog_product/price',
            'entity_id',
            null,
            'left',
            $storeId
        );
        $products->joinAttribute(
            'position',
            'catalog_product/position',
            'entity_id',
            null,
            'left',
            $storeId
        );
        $products->addCategoryIds();

        $totalRecords = $products->getSize();

        $products->getSelect()->order(['sku ASC']);

        if (file_exists($file)) {
            $lineCount = iterator_count($this->getLines($file)) + $pageSize; // the number of lines in the file
        }

        $totalPages = ceil($totalRecords/$pageSize);
        $pageNumber = $lineCount/$pageSize;

        if($pageNumber > $totalPages) {
            return false;
        }

        $products->getSelect()->limitPage($pageNumber, $pageSize);

        $writeData = [];

        /** @var Mage_Catalog_Model_Product $product */

        while ($product = $products->fetchItem()) {

            if (empty($product->getSku())) {
                continue;
            }

//            var_dump(
//                $product->toArray()
//            );die;
            $catalogItem = new \Ebizmarts\Recommendations\Data\V4_0\CatalogItem();
            $catalogItem->setId($product->getSku());
            $catalogItem->setName($product->getName());
            $catalogItem->setCategory(implode("-", $product->getCategoryIds()));
//            $catalogItem->setDescription(
//                json_encode(strip_tags($product->getDescription()))
//            );
            $catalogItem->setDescription(preg_replace("/[\n\r]/","", strip_tags($product->getDescription())));
            $catalogItem->setFeaturesList("Price=" . $product->getPrice() . ",Position=" . $product->getPosition());

            array_push($writeData, $catalogItem);
        }

        $csvWriter = new \Ebizmarts\Recommendations\RecommenderFile(
            new \Ebizmarts\Recommendations\Filesystem\CsvWriter($file)
        );
        $csvWriter->writeToCsv($writeData);

    }
}