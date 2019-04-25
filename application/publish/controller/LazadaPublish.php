<?php
/**
 * author:kevin
 * date:2019.4.18
 */

namespace app\publish\controller;

use think\Request;
use think\Exception;
use app\common\controller\Base;
use app\common\service\Common as CommonService;
use app\publish\service\LazadaPublishHelper;
use app\publish\helper\lazada\LazadaHelper;
use app\publish\service\LazadaListingService;
use think\Cache;

/**
 * @module LAZADA刊登系统
 * @title LAZADA刊登
 * @author KEVIN
 * Class LazadaListing
 * @package app\publish\controller
 */
class LazadaPublish extends Base
{
    private $helpers;
    private $actUser;

    public function __construct(Request $request = null)
    {
        parent::__construct($request);
        $this->helpers = new LazadaPublishHelper();
        $this->actUser = CommonService::getUserInfo(request());//['user_id' => 1,realname'=> 'swoole','username'=> 'swoole']


    }
    /**
     * @title 获取lazada待刊登商品列表
     * @url /publish/lazada/unpublishedList
     * @method get
     * @access public
     * @return \think\response\Json
     */
    public function unpublishedList(Request $request)
    {
        try {
            $params = $request->param();

            $data = $this->helpers->getUnpublishedListByChannelId($params,$params['page'], $params['pageSize']);

            return json($data);

        } catch (Exception $e) {

            return json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * @title 获取lazada选中产品刊登的详情
     * @url /publish/lazada/productInfo
     * @method get
     * @access public
     * @throws \Exception
     */
    public function GetProductInfo(Request $request)
    {
        try {
            $goodsId = $request::instance()->get('goods_id');
            if(empty($goodsId))throw new \Exception('产品ID不能为空！');
            $lazadaHelper = new LazadaPublishHelper();
            return json($lazadaHelper->GetProductInfoByGoodId($goodsId));
        }
        catch (\Exception $e)
        {
            return json($e->getMessage(),400);
        }
    }

    /**
     * @title lazada批量删除已刊登产品
     * @url /publish/lazada/productsDel
     * @method get
     * @param Request $request seller_sku_list = [['account_id'=>'1','item_sku'=>'1a'],....]
     * @return string
     * @throws \Exception
     */
    public function productsDel(Request $request)
    {

        try {
            $parameters = $request->get('seller_sku_list');
            $parameters = json_decode($parameters,true);
            if(!is_array($parameters) && !empty($parameters[0]['account_id'])){
                return '传入的数据必须为数组且不能为空';
            }
            $reslut =  (new LazadaPublishHelper())->removeProducts($parameters);
            if($reslut){
                return json($reslut,200);
            }
        }
        catch (\Exception $e)
        {
            return json($e->getMessage(),400);
        }
    }


    /**
     * @title Lazada刊登保存为草稿
     * @url publish/lazada/draftSave
     * @access public
     * @method post
     * @input Request $request
     * @return json
     */
    public function draftSave(Request $request)
    {
        try {
            $post = $request->instance()->param();

            $actUser = $this->actUser;
            $post['uid'] = isset($actUser['user_id']) ? $actUser['user_id'] : 0;


            if (empty($post['parent_sku'])) {
                return json(['message' => '商品spu不能为空'], 500);
            }

//            $spu = $post['parent_sku'];
            $goods_id = isset($post['goods_id']) ? $post['goods_id'] : '';


            $options['type'] = 'file';
            Cache::connect($options);

            if (isset($post['vars']) && $post['vars']) {
                $vars = json_decode($post['vars'], true);

                foreach ($vars as &$v) {
                    if (is_string($v['cron_time'])) {
                        $v['cron_time'] = strtotime($v['cron_time']);
                    }
                    $variants = $v['variant'];
                    foreach ($variants as &$variant) {
                        if (!isset($variant['cost_price'])) {
                            $variant['cost_price'] = isset($variant['cost']) ? $variant['cost'] : 9999;
                        }
                    }
                    $v['variant'] = $variants;
                }
                $post['vars'] = json_encode($vars);
            }

            $res = Cache::set('LazadaPublishCache:' . $goods_id . '_' . $post['uid'], $post, 0);

            if ($res) {
                if ((new LazadaPublishHelper())->draftSave($post)) {
                    return json(['message' => '保存为草稿成功']);
                } else {
                    return json(['message' => '保存为草稿失败'], 400);
                }
            } else {
                return json(['message' => '缓存写入失败'], 500);
            }
        } catch (JsonErrorException $exp) {
            throw new JsonErrorException($exp->getMessage());
        }
    }







    public function test(Request $request)
    {
//        $wh = [
//            'platform_status' => 1,
//            'app_key' => ['neq', ''],
//            'status' => 1,
//        ];
//        $accounts = (new \app\common\model\lazada\LazadaAccount())->where($wh)->select();
//        foreach ($accounts as $k => $v) {
//            $accountId = $v['id'];
//            $offset = 40000;
//            $pageSize = 1000;
//            $updateTime = '';
//            $response  = (new LazadaHelper())->syncBrands($accountId, $offset, $pageSize, $updateTime);//数组
//            return $response;
//        }
    }


}
