<?php
/**
 * Created by PhpStorm.
 * User: joy
 * Date: 18-5-30
 * Time: 下午6:56
 */

namespace app\common\model\shopee;


use think\Model;

class ShopeeAttribute extends Model
{
    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
    }
    public function getOptionsAttr($v){
        return json_decode($v,true);
    }
}