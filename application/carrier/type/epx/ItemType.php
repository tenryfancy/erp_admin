<?php
namespace app\carrier\type\epx;

class ItemType extends \app\carrier\type\BaseType
{
    /**
     * @var array Properties belonging to objects of this class.
     */
    private static $propertyTypes = [    
        'sku' => [
            'type' => 'string',
            'repeatable' => false,
            'required' => true
        ],
        'quantity' => [
            'type' => 'integer',
            'repeatable' => false,
            'required' => true
        ],
        'skuLabelCode' => [
            'type' => 'string',
            'repeatable' => false,
            'required' => false
        ]
    ];

    /**
     * @param array $values Optional properties and values to assign to the object.
     */
    public function __construct(array $values = [])
    {
        list($parentValues, $childValues) = self::getParentValues(self::$propertyTypes, $values);
        
        parent::__construct($parentValues);

        if (!array_key_exists(__CLASS__, self::$properties)) {
            self::$properties[__CLASS__] = array_merge(self::$properties[get_parent_class()], self::$propertyTypes);
        }

        $this->setValues(__CLASS__, $childValues);
    }
}

