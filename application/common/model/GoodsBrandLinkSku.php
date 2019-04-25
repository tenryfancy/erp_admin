<?php


namespace app\common\model;


use app\common\cache\Cache;
use app\goods\service\GoodsBrandsLink;
use app\goods\service\GoodsHelp;
use think\Model;

class GoodsBrandLinkSku extends Model
{
    protected $table = 'goods_brand_link_sku';

    protected $autoWriteTimestamp = false;

    protected $append = ['goods_category', 'status', 'creator'];

    protected $hidden = ['goods_id', 'first_push_time', 'creator_id', 'sku_status'];

    protected function getGoodsCategoryAttr($value, $data)
    {
        $goodsId = $data['goods_id'];
        //$goodsInfo = Cache::store('goods')->getGoodsInfo($goodsId); //并没有拿到获取器的category
        $goodsInfo = Goods::get($goodsId);
        $namePath = $goodsInfo['category'];
        return $namePath;
    }

    protected function getBrandLinkCategoryAttr($value, $data)
    {
        $goodsId = $data['goods_id'];
        $goodsInfo = Cache::store('goods')->getGoodsInfo($goodsId);
        $categoryId = $goodsInfo['category_id'];
        $categoryInfo = Cache::store('category')->getCategory($categoryId);
        $platform = $categoryInfo['platform'];
        if (empty($platform)) {
            return 'erp分类：【' . $categoryInfo['title'] . '】 没有平台分类';
        }
        $channelIdArr = array_column($platform, 'channel_id');
        if (!in_array(31, $channelIdArr)) {
            return 'erp分类：【' . $categoryInfo['title'] . '】没有添加品连分类映射';
        }
        $namePath = '';
        foreach ($platform as $v) {
            if ($v['channel_id'] != 31) {
                continue;
            }
            $channelCategoryId = $v['channel_category_id'];
            $namePath = (new GoodsBrandsLink())->getBrandLinkCategory($channelCategoryId);
        }
        return $namePath;
    }


    protected function getCreateTimeAttr($value)
    {
        return date('Y-m-d H:i:s', $value);
    }

    protected function getUpdateTimeAttr($value)
    {
        return date('Y-m-d H:i:s', $value);
    }

    //sku的状态
    protected function getStatusAttr($value)
    {
        $skuStatus = (new GoodsHelp())->sku_status;
        return $skuStatus[$value] ?? '';
    }

    //推送sku时 sku的状态(快照)
    protected function getSkuStatusAttr($value)
    {
        $skuStatus = (new GoodsHelp())->sku_status;
        return $skuStatus[$value] ?? '';
    }

    //推送状态
    protected function getPushStatusAttr($value)
    {
        $pushStatus = GoodsBrandsLink::PUSH_STATUS;
        return $pushStatus[$value] ?? '';
    }

    protected function getPushTimeAttr($value)
    {
        return $value ? date('Y-m-d H:i:s', $value) : '';
    }

    protected function getCreatorAttr($value, $data)
    {
        $realname = Cache::store('user')->getOneUserRealname($data['creator_id']);
        return $realname ? : '';
    }
}