<?php

class Ebizmarts_Recommender_Model_System_Config_Source_Productattribute
{
    public function toOptionArray()
    {
        $collection = Mage::getResourceModel('catalog/product_attribute_collection')
            ->addVisibleFilter();

        $collection->getSelect()->order('frontend_label ASC');

        $options = array();
        $options []= array(
            'value' => '',
            'label' => ''
        );

        foreach ($collection as $attribute) {
            $options []= array(
                'value' => $attribute->getAttributeCode(),
                'label' => $attribute->getFrontendLabel()
            );
        }

        return $options;
    }

    public function toOptions()
    {
        return array();
    }
}