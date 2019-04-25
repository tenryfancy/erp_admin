<?php
namespace app\listing\service;
use app\common\exception\JsonErrorException;
use app\common\exception\TaskException;
use app\common\model\GoodsGallery;
use app\common\model\GoodsSkuMap;
use app\common\model\GoodsTortDescription;
use app\common\model\User;
use app\common\model\wish\WishExpress;
use app\common\model\wish\WishPlatformShippableCountries;
use app\common\model\wish\WishShippableCountries;
use app\common\model\wish\WishWaitUploadProductInfo;
use app\common\service\CommonQueuer;
use app\listing\queue\WishCombineSkuQueue;
use app\listing\queue\WishListingUpdateQueue;
use app\listing\validate\WishListingValidate;
use app\common\model\wish\WishWaitUploadProduct;
use app\common\model\wish\WishWaitUploadProductVariant;
use app\common\model\wish\WishReplenishment;
use app\common\model\wish\WishAccount;
use app\publish\queue\WishQueue;
use app\publish\service\GoodsImage;
use service\wish\WishApi;
use think\Db;
use app\common\model\wish\WishActionLog;
use think\Exception;
use app\common\cache\Cache;
use think\exception\DbException;
use think\exception\PDOException;
use app\common\service\SwooleQueueJob;
/**
 * @node wish平台在线listing助手
 * Class WishListingHelper
 * @package app\listing\service
 */

class WishListingHelper {
    protected  $productModel;
    protected  $variantModel;
    const UPDATEPRODUCTAPI="https://merchant.wish.com/api/v2/product/update";
    const UPDATEVARIANTAPI="https://merchant.wish.com/api/v2/variant/update";
    const TYPE=[
        'updateProduct'=>1,
        'updateVariant'=>2,
        'enableProduct'=>3,
        'disableProduct'=>4,
        'enableVariant'=>5,
        'disableVariant'=>6,
        'updateInventory'=>7,
        'updateShipping'=>8,
        'updateMultiShipping'=>9,
        'updateQipaMultiShipping'=>10,
        'online'=>11,
        'disableWishExpress'=>12,
    ];
    public  function __construct()
    {
    	if(is_null($this->productModel))
	    {
		    $this->productModel = new WishWaitUploadProduct;
	    }
	    if(is_null($this->variantModel))
	    {
		    $this->variantModel = new WishWaitUploadProductVariant;
	    }

    }
    /***
     * 取消wish express
     * @param $products product_id商品id
     * @param $uid 用户id
     */
    public static function disableWishExpress($product_ids,$uid)
    {
        try{
            $products = explode(';',$product_ids);
            $count = 0;
            $type = self::TYPE['disableWishExpress'];
            foreach ($products as $product)
            {
                $res  = self::saveDisableWishExpress($product,$uid,$type);
                if($res)
                {
                    ++$count;
                }
            }
            return ['message'=>'成功取消['.$count.']条wish express'];
        }catch (Exception $exp){
            throw new JsonErrorException($exp->getMessage());
        }
    }

    /**
     * 保存
     * @param $product_id
     * @param $uid
     */
    public static function saveDisableWishExpress($product_id,$uid,$type)
    {
        $map=[
            'product_id'=>['=',$product_id],
            'wish_express_countries'=>['<>','']
        ];
        $product = WishWaitUploadProduct::where($map)->field('id,wish_express_countries')->find();
        if (empty($product))
        {
            return false;
        }

        $where=[
            'create_id'=>['=',$uid],
            'status'=>['=',0],
            'type'=>['=',$type],
            'product_id'=>['=',$product_id],
        ];
        $log=[
            'create_id'=>$uid,
            'type'=>$type,
            'new_data'=>'取消wish express设置',
            'product_id'=>$product_id,
            'old_data'=>$product['wish_express_countries'],
            'create_time'=>time(),
        ];

        if((new self())->WishActionLog($log,$where))
        {
            return true;
        }else{
            return false;
        }
    }
    /***
     * 批量设置wish express
     * @param $products product_id商品id
     * @param $express wish express数据
     */
    public static function batchSettingExpress($product_ids,$express,$uid)
    {
        try{

            $products = explode(';',$product_ids);
            $count = 0;
            $error=[];
            foreach ($products as $product)
            {
                $res  = self::addMultiShipping($product,$uid,$express,1);
                if($res['result'])
                {
                    ++$count;
                } else {
                    $error[]=$product;
                }
            }
            return ['message'=>'成功设置['.$count.']条wish express',
                    'error'  =>$error]; //返回失败的product_id-pan
        }catch (Exception $exp){
            throw new JsonErrorException($exp->getMessage());
        }

    }
    /**
     * 获取wish express表数据
     */
    public static function wishExpressData()
    {
        $cache = Cache::handler(true);

        if($return = $cache->get('wish_shippable_countries'))
        {
            $return = json_decode($return, true);
        }else{

            $all_country_shipping = (new WishShippableCountries())->select();
            foreach ($all_country_shipping as $key => &$shipping)
            {
                $return[$key]['shipping_price']=0;
                $return[$key]['country_code']=$shipping['code'];
                $return[$key]['wish_express']=0;
                $return[$key]['enabled']=0;
                $return[$key]['country']=$shipping['name'];
                $return[$key]['use_product_shipping']='';
            }
            $cache->set('wish_shippable_countries', json_encode($return));
        }
        return $return;
    }

    /**
     * 查询操作日志
     * @param array $param
     * @param int $page
     * @param int $pageSize
     * @param string $fields
     * @return array
     */
    public function getLogs($param=[], $page=1, $pageSize=30, $fields='*')
    {
        $where= [
            'product_id'=>['=',$param['product_id']],
        ];

        $const =[
            'name'=>'刊登标题',
            'description'=>'详情描述',
            'tags'=>'tags',
            'brand'=>'品牌',
            'landing_page_url'=>'展示页面',
            'upc'=>'upc',
            'main_image'=>'主图',
            'extra_images'=>'辅图',
            'max_quantity'=>'最大库存',
            'inventory'=>'库存',
            'price'=>'售价',
            'shipping'=>'运费',
            'enabled'=>'是否有效',
            'size'=>'size',
            'color'=>'颜色',
            'msrp'=>'msrp',
            'shipping_time'=>'发货日期',
            'warehouse_name'=>'仓库',
            'combine_sku'=>'捆绑sku'
        ];


        $count = (new WishActionLog())->where($where)->count();

        $data = (new WishActionLog())->order('create_time Desc')->with(['user'=>function($query){$query->field('id,realname');}])->where($where)->page($page,$pageSize)->select();

        if($data)
        {
            foreach ($data as &$d)
            {
                if(is_array($d['new_data']))
                {
                    $log='';

                    foreach ($d['new_data'] as $name=>$v)
                    {
                        if(is_numeric($name))
                        {
                            $log='Wish Express';
                        }else{
                            $log=$log.$const[$name].':由['.$d['old_data'][$name].']改为['.$d['new_data'][$name].']'.'<br />';
                        }
                    }
                }else{
                    $log=$d['new_data'];
                }
                $d['log']=$log;
            }
        }

        return ['data'=>$data,'count'=>$count,'page'=>$page,'pageSize'=>$pageSize];
    }

	/**
	 * 获取所有的sku是否都刊登成功了
	 */
    public function getVariantPublishStatus($pid)
    {
    	$where=[
    		'pid'=>['=',$pid],
		    'status'=>['<>',1],
	    ];

	    if(WishWaitUploadProductVariant::where($where)->find())
	    {
	    	return true;
	    }else{
	    	return false;
	    }
    }
	/**
	 * 更新变体信息
	 * @param $where
	 * @param $update
	 */
	public function updateProductEnabled($where,$update)
	{
		WishWaitUploadProduct::where($where)->update($update);
	}
	/**
	 * 更新变体信息
	 * @param $where
	 * @param $update
	 */
    public function updateVariantEnabled($where,$update)
    {
	    WishWaitUploadProductVariant::where($where)->update($update);
    }
    /**
     * 修改sku在线销售状态
     * @param string $product_id
     */
    public function updateVariantOnlinStatus($product_id,$enable)
    {
        if($product_id && $enable!='')
        {
            $productInfo = $this->productModel->get(['product_id'=>$product_id]);
            if($productInfo)
            {
                $productInfo = is_object($productInfo)?$productInfo->toArray():$productInfo;
                $pid = $productInfo['id'];
                $this->variantModel->isUpdate(true)->save(['enabled'=>$enable],['pid'=>$pid]);
            }
        }
    }

    public  function updateProductLockStatus($where)
    {
        return $this->productModel->save(['lock_update'=>0],$where);
    }

    public  function ProductStat($variantModel,$where=[])
    {
        if(empty($where))
        {
            return false;
        }else{
            $v=[];
        }

        if($variantModel->where($where)->find())
        {
            $min_price = $variantModel->where($where)->min('price');
            $max_price = $variantModel->where($where)->max('price');
            $min_shipping = $variantModel->where($where)->min('shipping');
            $max_shipping = $variantModel->where($where)->max('shipping');
            $v['inventory'] = $variantModel->where($where)->sum('inventory');
            $v['lowest_price']=$min_price;
            $v['highest_price']=$max_price;
            $v['lowest_shipping']=$min_shipping;
            $v['highest_shipping']=$max_shipping;
        }else{
            $v=[];
        }

        return $v;
    }
    /**
     * 更新已经刊登商品的资料
     * @param array $product
     */
    public  function editPublishedData($post)
    {
        try{
            if(isset($post['vars']))
            {
                $vars = json_decode($post['vars'],true); //每个账号信息
            }

            $uid = $post['uid'];

            $products =[]; //产品
            if(is_array($vars))
            {
                foreach ($vars as $k=>$var)
                {
                    $products[$k]['id'] = $post['id'];
                    $products[$k]['goods_id'] = $post['goods_id'];  //商品id
                    $products[$k]['name'] = $var['name']; //刊登标题
                    $products[$k]['main_image'] = $var['images'][0]; //商品主图
                    $products[$k]['description'] = $var['description'];//详情描述
                    $products[$k]['tags'] = $var['tags']; //Tags
                    $products[$k]['parent_sku'] = $post['parent_sku']; //SPU
                    $products[$k]['brand'] = $post['brand']; //品牌
                    $products[$k]['upc'] = $post['upc']; //UPC
                    $products[$k]['landing_page_url'] = $post['landing_page_url']; //商品展示页面
                    $products[$k]['extra_images'] =  implode('|', array_slice($var['images'], 1)); //商品相册a.jpg|b.jpg
                    $products[$k]['original_images'] =  implode('|', $var['images']);
                    $products[$k]['cron_time'] = strtotime($var['cron_time']); //定时刊登
                    $products[$k]['warehouse']=$post['warehouse'];
                    $products[$k]['is_virtual_send']=$var['is_virtual_send'];
                    $variants=[];
                    //更新时，每个商品的variant都已经存在，不存在variant为空的情况

                    if(isset($var['variant']) && !empty($var['variant']))
                    {
                        //$variant = json_decode($var['variant'],true);
                        $variants = $var['variant'];
                    }else{
                        $variant=WishWaitUploadProductVariant::where('pid','=',$post['id'])->find();
                        if($variant)
                        {
                            $variant= $variant->toArray();
                            $variants[0]['vid']=$variant['vid'];
                            $variants[0]['pid']=$variant['pid'];
                            $variants[0]['variant_id']=$variant['variant_id'];
                            $variants[0]['product_id']=$variant['product_id'];
                            $variants[0]['sku']=$variant['sku'];
                            $variants[0]['price']=$var['price'];
                            $variants[0]['price']=$var['price'];
                            $variants[0]['msrp']=$var['msrp'];
                            $variants[0]['inventory']=$var['inventory'];
                            $variants[0]['shipping']=$var['shipping'];
                            $variants[0]['shipping_time']=$var['shipping_time'];
                        }
                        /*$variants = array(
                           // 'sku'=>$post['parent_sku'],
                            'pid'=>$post['id'],
                            'main_image'=>$var['images'][0],
                            'price'=>$var['price'],
                            'msrp'=>$var['msrp'],
                            'inventory'=>$var['inventory'],
                            'shipping'=>$var['shipping'],
                            'color'=>'',
                            'size'=>'',
                            'status'=>0,
                            'shipping_time'=>$var['shipping_time'],
                        );*/
                    }
                    $products[$k]['variants']=$variants;
                }
            }

            if($products)
            {
                if(is_array($products))
                {
                    foreach($products as $p)
                    {
                        Db::startTrans();
                        try{
                            $pid = $p['id'];
                            if( $this->saveWishProductUpdateField($uid,$p))
                            {
                                $map = [
                                    'goods_id' => $p['goods_id'],
                                    'channel_id' => 3,
                                ];
                                GoodsSkuMap::update(['is_virtual_send'=>$p['is_virtual_send']],$map);
                                $this->productModel->save(['lock_product'=>1,'lock_update'=>1],['id'=>$p['id']]);
                            }
                            $variants = $p['variants'];
                            unset($p['variants']);
                            if(is_array($variants) && $variants)
                            {
                                foreach ($variants as $variant)
                                {

                                    if(!empty($variant))
                                    {

                                        if(isset($variant['vid']) && isset($variant['pid']))
                                        {
                                            if($this->saveWishVariantUpdateField($uid,$variant))
                                            {
                                                $this->variantModel->update(['lock_variant'=>1],['vid'=>$variant['vid']]);

                                                $this->productModel->update(['lock_update'=>1],['id'=>$variant['pid']]);
                                            }
                                        }else{
                                            $variant['pid'] = $pid;
                                            (new WishWaitUploadProductVariant())->isUpdate(false)->allowField(true)->save($variant);
                                        }
                                    }
                                }
                            }
                            Db::commit();
                            $return=['result'=>true,'message'=>'更新成功'];
                        }catch (PDOException $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }catch (DbException $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }catch (Exception $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }

                    }
                }
            }
            return $return;
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }

    /**
     * 保存发生了变化的数据，记录到action_log
     * @param $uid 用户id
     * @param $variant
     * @return bool
     */
    public function saveWishVariantUpdateField($uid,$variant)
    {
        try{
            $fields =['combine_sku','sku','inventory','price','shipping','enabled','size','color','msrp','shipping_time','main_image','warehouse_name'];
            $update=false;

            if(isset($variant['vid']) && $variant['vid'])
            {
                $object = WishWaitUploadProductVariant::where('vid','=',$variant['vid'])->limit(1)->find();

                if($object)
                {
                    $row = is_object($object)?$object->toArray():$object;

                    $row['combine_sku'] = $object->getData('combine_sku');
                    $new_data=$old_data=[];
                    foreach ($variant as $type=>$value)
                    {
                        //当新提交的数据和原始数据不一样，且在指定修改的字段中时

                        if(in_array($type,$fields) && $row[$type]!=$variant[$type])
                        {
                            $new_data[$type]=$variant[$type];
                            $old_data[$type]=$row[$type];
                        }
                    }

                    if($new_data && $old_data)
                    {

                        $keys =array_keys($new_data);

                        $where=[
                            'create_id'=>['=',$uid],
                            'new_data'=>['=',json_encode($new_data)],
                            'variant_id'=>['=',$row['variant_id']],
                            'status'=>['=',0],
                        ];
                        $log=[
                            'create_id'=>$uid,
                            'type'=>self::TYPE['updateVariant'],
                            'new_data'=>json_encode($new_data),
                            'old_data'=>json_encode($old_data),
                            'product_id'=>$row['product_id'],
                            'variant_id'=>$row['variant_id'],
                            'create_time'=>time(),
                        ];
                        //如果修改了捆绑销售
                        if(isset($new_data['combine_sku']))
                        {
                            //如果只是编辑了捆绑sku
                            if(count($keys)==1 && $keys[0]=='combine_sku')
                            {
                                $log['status']=1;
                            }

                            $queue=[
                                'vid'=>$variant['vid'],
                                'combine_sku'=>$variant['combine_sku']
                            ];

                            (new CommonQueuer(WishCombineSkuQueue::class))->push($queue);
                        }


                        if($this->wishActionLog($log,$where))
                        {
                            $update=true;
                        }
                    }
                }
            }
            return $update;
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }
    /**
     * 保存发生了变化的数据，记录到action_log
     * @param $uid 用户id
     * @param $product
     * @return bool
     */
    public function saveWishProductUpdateField($uid,$product)
    {
        try{
            $fields =['name','tags','brand','upc','main_image','max_quantity','is_virtual_send'];
            $info=['description','landing_page_url','extra_images',];
            $update=false;
            if(isset($product['id']) && $product['id'])
            {
                foreach ($product as $type=>$value)
                {
                    if(in_array($type,$fields))
                    {
                        $row = WishWaitUploadProduct::where('id','=',$product['id'])->limit(1)->find();
                    }elseif(in_array($type,$info)){
                        $row = WishWaitUploadProductInfo::where('id','=',$product['id'])->limit(1)->find();
                    }else{
                        $row=[];
                    }

                    if($row)
                    {
                        $new_data=$old_data=[];
                        if($row[$type]!=$product[$type])
                        {
                            $new_data[$type]=$product[$type];
                            $old_data[$type]=$row[$type];
                        }
                        if($new_data && $old_data)
                        {
                            $where=[
                                'create_id'=>['=',$uid],
                                'new_data'=>['=',json_encode($new_data)],
                                'product_id'=>['=',$row['product_id']],
                                'status'=>['=',0],
                            ];
                            $log=[
                                'create_id'=>$uid,
                                'type'=>self::TYPE['updateProduct'],
                                'new_data'=>json_encode($new_data),
                                'old_data'=>json_encode($old_data),
                                'product_id'=>$row['product_id'],
                                'create_time'=>time(),
                            ];

                            if($this->wishActionLog($log,$where))
                            {
                                $update=true;
                            }
                        }
                    }

                }
            }
            return $update;

        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }


    /**
     * 批量修改
     * @param array $post
     * @param type $type
     * @return string
     */
    public  function batchEditSingleField(array $post,$type,$cron_time=0,$remark='')
    {
        try{
            $product_fields = $this->productAllowUpdateFields();
            $variant_fields = $this->variantAllowUpdateFields();
            $uid = $post['uid'];
            if(is_array($post))
            {
                if(in_array($type,$product_fields ))
                {
                    foreach($post as $p)
                    {
                        //$data[$type]=$p[$type];
                        $data['lock_product']=1;
                        $data['lock_update']=1;
                        $product_id = $p['product_id'];
                        //记录数据，等修改成功了更新表数据
                        $row = WishWaitUploadProduct::where('product_id','=',$product_id)->field($type)->find();

                        if($row)
                        {
                            $row = $row->toArray();

                            $new_data=[
                                $type=>$p[$type]
                            ];
                            $old_data=[
                                $type=>$row[$type]
                            ];

                            $where['product_id']=['=',$product_id];
                            $where['status']=['=',0];
                            $where['create_id']=['=',$uid];
                            $where['new_data']=['=',json_encode($new_data)];


                            $log=[
                                'create_id'=>$uid,
                                'new_data'=>json_encode($new_data),
                                'old_data'=>json_encode($old_data),
                                'create_time'=>time(),
                                'type'=>self::TYPE['updateProduct'],
                                'product_id'=>$product_id,
                                'cron_time'=>strtotime($cron_time),
                                'remark'=>$remark,
                            ];
                            if($this->wishActionLog($log,$where))
                            {
                                $message = WishWaitUploadProduct::where('product_id','=',$product_id)->update($data);
                            }
                        }

                    }
                    $message=false;
                }elseif(in_array($type, $variant_fields)){

                    foreach($post as $p)
                    {
                        $variant_id = $p['variant_id'];
                        $data=[];
                        //$data[$type]=$p[$type];
                        $data['lock_variant']=1;

                        $row = WishWaitUploadProductVariant::where('variant_id','=',$variant_id)->field($type.',product_id,pid')->find();

                        if($row)
                        {
                            $row = $row->toArray();

                            $product_id = $row['product_id'];

                            $pid = $row['pid'];

                            $new_data=[
                                $type=>$p[$type]
                            ];
                            $old_data=[
                                $type=>$row[$type]
                            ];


                            //$where['product_id']=['=',$product_id];
                            $where['variant_id']=['=',$variant_id];
                            $where['status']=['=',0];
                            $where['create_id']=['=',$uid];
                            $where['new_data']=['=',json_encode($new_data)];

                            $log=[
                                'create_id'=>$uid,
                                'new_data'=>json_encode($new_data),
                                'old_data'=>json_encode($old_data),
                                'create_time'=>time(),
                                'type'=>self::TYPE['updateVariant'],
                                'product_id'=>$product_id,
                                'variant_id'=>$variant_id,
                                'cron_time'=>strtotime($cron_time),
                                'remark'=>$remark,
                            ];
                            if($this->wishActionLog($log,$where))
                            {
                                $message = WishWaitUploadProductVariant::where('variant_id','=',$variant_id)->update($data);
                                $update['lock_update']=1;
                                WishWaitUploadProduct::where('id','=',$pid)->update($update);
                            }
                        }

//                        $message = WishWaitUploadProductVariant::where('variant_id','=',$variant_id)->update($data);
//                        //sku更新了也要更新product lock_variant，表示有更新
//                        $productInfo = WishWaitUploadProductVariant::where('variant_id','=',$variant_id)->field('vid,pid')->find();
//                        if($productInfo ) //如果更新状态是没有更新，则更新为1
//                        {
//                            $pid= $productInfo['pid'];
//                            $update=$this->ProductStat(new WishWaitUploadProductVariant,['pid'=>$pid]);
//                            $update['lock_update']=1;
//                            WishWaitUploadProduct::where('id','=',$pid)->update($update);
//                        }
                    }
                    $message=false;
                }else{
                    $message ='要更新的字段不存在';
                }
                return $message;
            }
        }catch (Exception $exp){
            throw new JsonErrorException($exp->getFile().$exp->getLine().$exp->getMessage());
        }

    }

    /**
     * 获取所有商品所有物流数据
     * @param array $product
     * @reutn void
     */
    public static function getAllShipping($product)
    {
        $api = WishApi::instance($product)->loader('Product');
        $response = $api->getAllShipping($product);
        if($response['state']==true)
        {
            $shipping_prices = $response['data']['ProductCountryAllShipping']['shipping_prices'];
            $data['all_country_shipping']= json_encode($shipping_prices);
            self::updateCountryAllShipping($data, ['id'=>$product['pid']]);
        }
    }
    /**
     * 更新产品所有的国家物流设置
     * @param array $data
     * @param array $where
     */
    public static function updateCountryAllShipping($data,$where)
    {
       $model = new WishWaitUploadProduct;
       Db::startTrans();
       try {
           $model->update($data, $where);
           Db::commit() ;
       } catch (\Exception $exp) {
           Db::rollback();
           throw new TaskException($exp->getMessage());
       }catch (DbException $exp){
           Db::rollback();
           throw new TaskException($exp->getMessage());
       }catch (TaskException $exp) {
           Db::rollback();
           throw new TaskException($exp->getMessage());
       }
    }
    /**
     * 更新商品物流
     * @param type $product_id
     * @param type $uid
     */
    public  function updateMultiShipping($product_id,$uid)
    {
        try{
            $where['e.product_id']=['eq',$product_id];
            $where['e.create_id']=['eq',$uid];
            $where['e.status']=['neq',1];

            $fields="e.*,p.id pid,a.access_token";

            $product = (new WishActionLog())->alias('e')
                ->join('wish_wait_upload_product p','e.product_id=p.product_id' , 'LEFT')
                ->join('wish_account a','p.accountid=a.id','LEFT')
                ->field($fields)->order('id desc')->where($where)->limit(1)->find();
            if($product)
            {
                $product=$product->toArray();
                $product['product']['account']['access_token']=$product['access_token'];
                $response = WishItemUpdateService::updateMultiShipping($product);
                if($response['status']==1)
                {
                    return ['result'=>true,'message'=>'修改成功'];
                }elseif($response['status']==2){
                    return ['result'=>false,'message'=>$response['message']];
                }

            }else{
                return ['result'=>false,'message'=>'没有找到记录'];
            }
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }


    }
    /**
     * 拼接wish更新商品运费数据
     * @param array $post
     * @return array $return
     */
    public function splitMultiShippingData(array $post)
    {

        if(isset($post['express_data']))
        {
            $return=[];
            $express_data  = json_decode($post['express_data'],true);

            $use_product_shipping_countries=[]; //使用产品运费
            $shipping_price=[]; //自定义运费
            $disabled_countries=[]; //不能购买国家
            $wish_express_add_countries=[];//wish express国家
            $wish_express_remove_countries=[]; //不使用wish express国家
            foreach ($express_data as $key => $data)
            {
                //运费设置
                if($data['use_product_shipping']==true)
                {
                    $use_product_shipping_countries[] = $data['country_code'];
                }else{
                    $shipping_price[$data['country_code']]=$data['shipping_price'];
                }
                //不能购买国家设置
                if($data['enabled']==false)
                {
                    $disabled_countries[]=$data['country_code'];
                }

                if($data['wish_express'])
                {
                    $wish_express_add_countries[]=$data['country_code'];
                }else{
                    $wish_express_remove_countries[] = $data['country_code'];
                }
            }
            $return=[
                'id'=>$post['product_id'],
                'use_product_shipping_countries'=> implode(',', $use_product_shipping_countries),
                'disabled_countries'=> implode(',', $disabled_countries),
                'wish_express_add_countries'=> implode(',', $wish_express_add_countries),
                'wish_express_remove_countries'=> implode(',', $wish_express_remove_countries),
                'access_token'=>$post['access_token'],
            ];
            $return = array_merge($return,$shipping_price);
        }else{
            $return=[];
        }

        return $return;

    }


    /**
     * 检测新物流费涨价超过20%，有的话的不让修改，涨幅20%的WISH平台不让修改-pan
     * @param $old_data
     * @param $new_data
     * @return array $return 返回超过20%的国家代码
     */
    public static function checkAllCountryShipping($default_shipping_price,$old_data,$new_data)
    {
        $old_data=json_decode($old_data,true);
        $new_data=json_decode($new_data,true);

        $old_price=$new_price=[];

        if (is_array($old_data))
        {
            foreach($old_data as $v)
            {
                if ($v['ProductCountryShipping']['use_product_shipping']=='0'
                    || $v['ProductCountryShipping']['use_product_shipping']=='False')
                {
                    $key=$v['ProductCountryShipping']['country_code'];
                    $old_price[$key]=$v['ProductCountryShipping']['shipping_price'];
                }

            }
        }

        foreach($new_data as $v)
        {
            if ($v['ProductCountryShipping']['use_product_shipping']=='0'
                || $v['ProductCountryShipping']['use_product_shipping']=='False')
            {
                $key=$v['ProductCountryShipping']['country_code'];
                $new_price[$key]=$v['ProductCountryShipping']['shipping_price'];
            }

        }

        $error=[];
        foreach($new_price as $k=>$v)
        {
            $tmp_price=isset($old_price[$k])?$old_price[$k]:$default_shipping_price;

            if (($v-$tmp_price)>$tmp_price*0.2)
            {
                $error[]=$k;
            }
        }
        if (count($error)>0)
        {
            return $error;
        } else {
            return;
        }



    }


    /***
     * 重新组合物流价格设置
     * @param $new
     * @param $old
     */
    private static function mergeShipping($newShipping,$oldShipping)
    {
        $new_shipping_arr= json_decode($newShipping,true);
        $tmp=[];
        foreach($new_shipping_arr as $v)
        {
            $key=$v['ProductCountryShipping']['country_code'];
            $tmp[$key]=$v;
        };

        $old_shipping_arr= json_decode($oldShipping,true);
        foreach($old_shipping_arr as &$v)
        {
            $code=$v['ProductCountryShipping']['country_code'];
            if (isset($tmp[$code]))
            {
                $v=$tmp[$code];
            }

        }
        return json_encode($old_shipping_arr);

    }



    /**
     * 更新所有国家的运费
     * @param varchar $product_id
     * @param int $uid
     * @param json $all_country_shipping
     * @return string
     */
    public  static function addMultiShipping($product_id,$uid,$all_country_shipping,$special=0)
    {
        //$shipping= json_decode($data, true);

        $save = [
          'uid'=>$uid,
          'product_id'=>$product_id,
          'express_data'=>$all_country_shipping,
          'update_time'=>time(),
        ];

        $shipping_arr= json_decode($all_country_shipping,true);

        $shipping=[];
        foreach($shipping_arr as $arr)
        {
            if($arr['use_product_shipping']==1)
            {
                $arr['shipping_price']='Use Product Shipping Price';
                $arr['use_product_shipping']=1;
            }
            $tmp['ProductCountryShipping']=$arr;
            $shipping[] = $tmp;
        }
        $shipping= json_encode($shipping);//新提交的

        if($special)
        {
            $type = self::TYPE['updateQipaMultiShipping'];
        }else{
            $type = self::TYPE['updateMultiShipping'];
        }

        $product = WishWaitUploadProductInfo::where(['product_id'=>$product_id])->find();

        if($product)
        {
            $product = $product->toArray();

            $old_data = $product['all_country_shipping'];
//            if(empty($old_data))
//            {
//                $old_data='';
//            }

            if(empty($old_data))
            {
                return ['result'=>false,'message'=>'旧数据不存在，无法设置。'];

            }

            if($shipping == $old_data)
            {
                return ['result'=>false,'message'=>'数据没有任何变化'];
            }else{
                //添加新规20%检测-pan
                //$product_variant=WishWaitUploadProductVariant::where(['product_id'=>$product_id])->find();
//                $objProduct = (new WishWaitUploadProduct())
//                    ->field('highest_shipping')
//                    ->where(['product_id'=>$product_id])
//                    ->find();
//
//                if (($check=self::checkAllCountryShipping( $objProduct->highest_shipping,$old_data,$shipping))!=null)
//                {
//                    return [
//                        'result'=>false,
//                        'message'=>'数据检测有误',
//                        'error'=>$check,
//                    ];
//
//                }

                $where=[
                    'create_id'=>['=',$uid],
                    'status'=>['=',0],
                    'product_id'=>['=',$product_id],
                    'new_data'=>['=',$shipping],
                ];
                $log=[
                    'create_id'=>$uid,
                    'type'=>$type,
                    'new_data'=>$shipping,
                    'product_id'=>$product_id,
                    'old_data'=>$old_data,
                    'create_time'=>time(),
                ];

                if((new self())->WishActionLog($log,$where))
                {
                    return ['result'=>true,'message'=>'修改wish express成功'];
                }else{
                    return ['result'=>false,'message'=>'修改wish express失败'];
                }
            }

        }else{
            throw new JsonErrorException('[产品ID为:'.$product_id.']的商品不存在');
        }


//        $row = (new WishExpress())->where(['product_id'=>$product_id,'uid'=>$uid,'state'=>0])->find();
//        if($row)
//        {
//            if((new WishExpress())->where(['id'=>$row['id']])->update($save))
//            {
//                $message= '更新wish express成功';
//            }else{
//                $message= '更新wish express失败';
//            }
//
//        }else{
//            if((new WishExpress())->save($save))
//            {
//                $message= '修改wish express成功';
//            }else{
//                $message= '修改wish express失败';
//            }
//        }
//        WishWaitUploadProduct::where(['product_id'=>$product_id])->update(['all_country_shipping'=>$shipping,'lock_update'=>1]);
        //return $message;
    }
    /**
     * 获取商品的all country shipping
     * @param type $product_id wish产品id
     * @return array $data
     */
    public static function getShippingByProductId($product_id)
    {
        try{
            $data = (new WishWaitUploadProductInfo())->field('product_id,all_country_shipping')->where(['product_id'=>$product_id])->find();

            if(empty($data['all_country_shipping']))
            {

                $WishExpress = (new WishExpress)->order('id desc')->where(['product_id'=>$product_id])->find();
                if($WishExpress)
                {
                    $data['all_country_shipping']  =  $WishExpress->toArray()['express_data'];
                    $data['all_country_shipping'] = json_decode($data['all_country_shipping'],true);
                    $all_country_shipping = $data['all_country_shipping'];

                    foreach ($all_country_shipping as &$v)
                    {
                        $v['enabled'] = (int)$v['enabled'];
                        $v['wish_express'] = (int)$v['wish_express'];
                    }
                    $return=$all_country_shipping;
                    return $return;
                }

            }

            if($data['all_country_shipping']!='null')
            {
                $data['all_country_shipping'] = json_decode($data['all_country_shipping'],true);

                $all_country_shipping = $data['all_country_shipping'];

                $return=[];
                if(is_array($all_country_shipping))
                {
                    //关联wish国家表
                    foreach ($all_country_shipping as $key => &$shipping)
                    {
                        #unset($shipping['shipping_price']);
                        unset($shipping['id']);

                        if($shipping['ProductCountryShipping']['shipping_price']=='Use Product Shipping Price')
                        {
                            $shipping['ProductCountryShipping']['shipping_price']='';
                        }

                        if($shipping['ProductCountryShipping']['wish_express']=='False')
                        {
                            $shipping['ProductCountryShipping']['wish_express']=0;
                        }else{
                            $shipping['ProductCountryShipping']['wish_express']=1;
                        }

                        if($shipping['ProductCountryShipping']['use_product_shipping']=='False')
                        {
                            $shipping['ProductCountryShipping']['use_product_shipping']=0;
                        }else{
                            $shipping['ProductCountryShipping']['use_product_shipping']=1;
                        }

                        if($shipping['ProductCountryShipping']['enabled']=='False')
                        {
                            $shipping['ProductCountryShipping']['enabled']=0;
                        }else{
                            $shipping['ProductCountryShipping']['enabled']=1;
                        }


                        $countryInfo = (new WishPlatformShippableCountries())->where(['abbreviations'=>$shipping['ProductCountryShipping']['country_code']])->find();
                        if($countryInfo)
                        {
                            $shipping['ProductCountryShipping']['country']=$countryInfo['full_name'];
                        }else{
                            $shipping['ProductCountryShipping']['country']='';
                        }
                        $return[$key]=$shipping['ProductCountryShipping'];
                    }
                    //$data['all_country_shipping'] = $all_country_shipping;
                }else{
                    $cache = Cache::handler(true);

                    if($return = $cache->get('wish_shippable_countries'))
                    {
                        $return = json_decode($return, true);
                    }else{

                        $all_country_shipping = (new WishPlatformShippableCountries())->select();
                        foreach ($all_country_shipping as $key => &$shipping)
                        {
                            $return[$key]['shipping_price']=0;
                            $return[$key]['country_code']=$shipping['abbreviations'];
                            $return[$key]['wish_express']=0;
                            $return[$key]['enabled']=0;
                            $return[$key]['country']=$shipping['full_name'];
                            $return[$key]['use_product_shipping']='';
                        }
                        $cache->set('wish_shippable_countries', json_encode($return));
                    }
                }
            }else{
                $return=[];
            }

            return $return;
        }catch (Exception $exp){
            throw new JsonErrorException($exp->getMessage());
        }

    }

    /**
     * 封装编辑指定国家运费数据
     * @param array $post
     * @param string $api
     * @return array
     */
    public function createUpdateShippingData($post,$api)
    {
        $return=[];
        if(isset($post['product_id']))
        {
            $return['product_id'] = $post['product_id'];
            //unset($post['product_id']);
        }

        if(isset($post['uid']))
        {
            $return['uid'] = $post['uid'];
            unset($post['uid']);
        }

        if(isset($post['shipping']) && isset($post['country']))
        {
            $return['action']='设置国家['.$post['country'].']运费为'.$post['shipping'];
        }
        $return['name']='shipping';
        $return['api']=$api;
        $return['data']= json_encode($post);
        $return['add_time']=time();

        return $return;
    }
    /**
     *
     * @return type
     *
     */
    public function createUpdateLog($updateData,$table,$uid)
    {
        //将更改的数据逐条写入数据库
        $productAllowUpdateFields=['name','description','tags','brand','landing_page_url','upc','main_image','extra_images'];
        $variantAllowUpdateFields=['inventory','price','shipping','enabled','size','color','msrp','shipping_time','main_image'];

        if($table=='product')
        {
            $allFields = $productAllowUpdateFields;
        }elseif($table=='variant'){
            $allFields=$variantAllowUpdateFields;
        }

        foreach($updateData as $field=>$value)
        {

           if(in_array( $field,$allFields))
           {
               if($table=='product')
               {
                   $currentProductFieldData = self::getProductData(['product_id'=>$updateData['id']],$field);

                    $Log=[
                        'uid'=>$uid,
                        'api'=>'https://china-merchant.wish.com/api/v2/product/update',
                        'name'=>$field,
                        'data'=>$value,
                        'action'=>'将['.$field.']'.$currentProductFieldData[$field].'更新为'.$value,
                        'product_id'=>$updateData['id'],
                        'add_time'=>time(),
                    ];

               }elseif($table=='variant'){

                    $currentProductFieldData = self::getVariantData(['variant_id'=>$updateData['variant_id']],$field);
                    $Log=[
                        'uid'=>$uid,
                        'api'=>' https://china-merchant.wish.com/api/v2/variant/update',
                        'name'=>$field,
                        'data'=>$value,
                        'action'=>'将['.$field.']'.$currentProductFieldData[$field].'更新为'.$value,
                        'product_id'=>$updateData['product_id'],
                        'variant_id'=>$updateData['variant_id'],
                        'add_time'=>time(),
                    ];
               }

               $result = $this->wishActionLog($Log,['uid'=>$Log['uid'],'name'=>$Log['name'],'product_id'=>$Log['product_id'],'code'=>0]);
           }
        }


    }
    /**
     * 获取wish_wait_upload_product表字段
     * @return array
     */
    public function  productAllowUpdateFields()
    {
        $model = new \app\common\model\wish\WishWaitUploadProduct;
        $tableFields = $model->getTableFields(['table'=>$model->getTable()]);
        foreach($tableFields as $ke=>$field)
        {
            if(in_array($field, ['price','shipping','inventory']))
            {
                unset($tableFields[$ke]);
            }
        }
        return $tableFields;
    }

    /**
     * 获取wish_wait_upload_product_variant表字段
     * @return array
     */
    public function  variantAllowUpdateFields()
    {
        $model = new \app\common\model\wish\WishWaitUploadProductVariant();
        $tableFields = $model ->getTableFields(['table'=>$model->getTable()]);

        return $tableFields;
    }

    /**
     * 上下架操作日志
     * @param $product_id
     * @param $uid
     * @param $message
     * @param int $cron_time
     * @param string $remark
     */
    public function disableInableAction($product_id,$type,$uid,$message,$cron_time=0,$remark='')
    {

        $where=[
            'product_id'=>['=',$product_id],
            'create_id'=>['=',$uid],
            'type'=>['=',self::TYPE[$type]],
            'status'=>['=',0]
        ];
        $log=[
            'product_id'=>$product_id,
            'type'=>self::TYPE[$type],
            'create_id'=>$uid,
            'new_data'=>$message,
            'old_data'=>'',
            'create_time'=>time(),
        ];
        return $this->wishActionLog($log,$where);
    }

    /**
     * 变体上下架操作日志
     * @param $variant 变体id
     * @param $type online/offline
     * @param $uid 用户id
     * @param $cron_time 定时时间
     * @param $remark 备注
     */
    public function variantOnOff($variant_id,$type,$uid,$cron_time=0,$remark='')
    {
        try{
            if($variant_id)
            {

                $variant = WishWaitUploadProductVariant::where('variant_id','=',$variant_id)->with(['product'=>function($query){$query->field('id,product_id');}])->find();
                if($variant)
                {
                    $variant= $variant->toArray();

                    if($variant['product_id'])
                    {
                        $product_id = $variant['product_id'];
                    }else{
                        $product_id = $variant['product']['product_id'];
                    }

                    if($type=='enableVariant')
                    {
                        $message='上架';
                    }elseif($type=='disableVariant'){
                        $message='下架';
                    }

                    $message = $variant['sku'].$message;

                    $where=[
                        'product_id'=>['=',$product_id],
                        'variant_id'=>['=',$variant_id],
                        'create_id'=>['=',$uid],
                        'type'=>['=',self::TYPE[$type]],
                        'status'=>['=',0]
                    ];
                    $log=[
                        'product_id'=>$product_id,
                        'variant_id'=>$variant['variant_id'],
                        'type'=>self::TYPE[$type],
                        'create_id'=>$uid,
                        'new_data'=>$message,
                        'old_data'=>'',
                        'create_time'=>time(),
                    ];

                    if($this->wishActionLog($log,$where))
                    {
                        return ['result'=>true,'message'=>$message.'成功，稍后执行'];
                    }else{
                        return ['result'=>false,'message'=>$message.'失败'];
                    }
                }else{
                    return ['result'=>false,'message'=>'没有相关记录'];
                }
            }else{
                return ['result'=>false,'message'=>'变体id不能为空'];
            }
        }catch (Exception $exp){
            throw new Exception($exp->getMessage());
        }
    }
    /**
     * 写入修改日志
     * @param array $log
     * @param array $where
     */
    public function wishActionLog(array $log,$where=[])
    {
        Db::startTrans();
        try{
            if($rs = WishActionLog::where($where)->field('id')->find()){
                $id = $rs['id'];
                $return =  WishActionLog::where('id',$rs['id'])->update($log);
            }else{
                $return = WishActionLog::create($log);
                $id = $return->id;
            }
            Db::commit();
            $cron_time=null;
            if(isset($log['cron_time']) && $log['cron_time']){
                if(is_string($log['cron_time'])){
                    $cron_time=strtotime($log['cron_time']);
                }elseif(is_numeric($log['cron_time'])){
                    $cron_time = $log['cron_time'];
                }
            }

            //如果产品已经在平台上下架
            try{
            (new WishQueue(WishListingUpdateQueue::class))->push($id,$cron_time);
            }catch (PDOException $exp){
                (new WishWaitUploadProduct())->where('pid',$where['product_id'])->update(true)->save(['enabled'=>0]);
            }


            return $return ;
        }catch (PDOException $exp){
            Db::rollback();
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        } catch (Exception $exp){
            Db::rollback();
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }
    /**
     * 比较更新sku与数据库sku作了哪些更新
     * @param array $postVariant
     * @return array $diffArray
     */
    public function variantArrayDiff(array $postVariant)
    {
        $diffArray = [];
        foreach ($postVariant as $key => $sku)
        {
           unset($sku['id']);
           $dbSkuData = self::getOneVariant(['vid'=>$sku['vid']],[],true);
           if(array_diff($sku, $dbSkuData))
           {
               $diff= array_diff($sku, $dbSkuData);
               $diff['sku'] = $sku['sku'];
               $diff['variant_id'] = $sku['variant_id'];
               $diff['product_id'] = $sku['product_id'];
               $diff['vid'] = $sku['vid'];
               $diff['pid'] = $sku['pid'];
               $diffArray[] = $diff;
           }
        }

        return $diffArray;
    }
    /**
     * @node 更新商品
     * @access public
     * @param array $products
     * @return  void
     */

    public function updateProduct(array $products)
    {
        set_time_limit(0);
        foreach ($products as $key => $product)
        {
            $config['access_token']=$product['access_token'];

            $api = WishApi::instance($config)->loader('Product');

            $data =[];
            $data['name']=$product['name'];
            $data['description']=$product['description'];
            $data['parent_sku']=$product['parent_sku'];
            $data['tags']=$product['tags'];
            if(is_array($product['images']))
            {
                $data['main_image']=$product['images'][0]; //主图              
                $data['extra_images']= implode('|', $product['extra_images']);//附图
            }
            $data['brand']=$product['brand'];
            $data['landing_page_url']=$product['landing_page_url'];
            $data['upc']=$product['upc'];
            $data['access_token']=$product['access_token'];

            $response = $api->updateProduct($data);

            if($response['state'] === true && $response['code'] == 0)
            {
                return ['state'=>true,'message'=>'更新成功'];
            }else{
               return ['state'=>false,'message'=>'更新失败'];
            }
        }
    }
    /**
     * 更新sku信息
     * @param array $product
     * @return array
     */
    public function updateSku($product)
    {
        $variant = [];

        $variant['sku']=$product['sku'];
        $variant['inventory']=$product['inventory'];
        $variant['price']=$product['price'];
        $variant['shipping']=$product['shipping'];
        $variant['color']=$product['color'];
        $variant['size']=$product['size'];
        $variant['msrp']=$product['msrp'];
        $variant['shipping_time']=$product['shipping_time'];
        $variant['main_image']=$product['main_image'];

        $response = $api->variantProduct($variant);

        if($response['state'] === true && $response['code'] == 0)
        {
            return ['state'=>true,'message'=>'更新成功'];
        }else{
           return ['state'=>false,'message'=>'更新失败'];
        }
    }

    /**
     * @node 验证提交数据合法性
     * @access public
     * @param array $post
     * @return string
     */
    public  function validateUpdate($post =array())
    {
        $WishListingValidate = new WishListingValidate();

        $error = $WishListingValidate->checkData($post);

        if($error)
        {
            return $error;
        }

        if(isset($post['vars']))
        {
            $vars = json_decode($post['vars'],true);

            if(is_array($vars) && !empty($vars))
            {
                $error = $WishListingValidate->checkVars($vars,'var');
                if($error)
                {
                    return $error;
                }
            }else{
                return 'vars不能为空';
            }
        }
    }

    /**
     * @node 添加补货
     * @access public
     * @param array $request
     * @return string
     */

    public static  function addBuhuo($post)
    {
        $model = new WishReplenishment();

        $where['product_id']=['=',$post['product_id']];
        $where['variant_id']=['=',$post['variant_id']];
        $where['status']=['=',0];

        if($model->existOne($where))
        {
            $message='之前的补货还未执行，暂时不能更新补货';
        }else{
            if($rs = $model->addOne($post) > 0)
            {
                 $message='补货成功';
            }else{
                $message=$model->getError();
            }
        }
        return $message;
    }

    /**
     * 获取产品数据
     * @param array $where
     * @param string $fields
     * @return array
     */
    public static function getProductData(array $where,$fields)
    {
       return (new WishWaitUploadProduct())->field($fields)->where($where)->find();
    }

    /**
     * 获取产品变体信息
     * @param array $where
     * @param string $fields
     * @return array
     */
    public static function getVariantData(array $where,$fields)
    {
       return (new WishWaitUploadProductVariant())->field($fields)->where($where)->find();
    }

    /**
     * @node 获取一个商品的sku
     * @param array $where
     * @param array $with
     * @return array
     */
     public static function getOneVariant(array $where,$with=[],$toArray=false)
     {
         if($toArray)
         {
             return WishWaitUploadProductVariant::getOne($where,$with)->toArray();
         }else{
             return WishWaitUploadProductVariant::getOne($where,$with);
         }

     }

     /**
     * @node 获取一个商品的所有sku
     * @param array $where
     * @param array $with
     * @return array
     */
     public static function getAllVariant(array $where,$fields='*')
     {
        return (new WishWaitUploadProductVariant())->field($fields)->where($where)->select();
     }
     /**
      * 根据商品id获取变体信息
      * @param string $fields 查询字段
      * @param array $where 查询条件
      * @return array
      */
     public  static function getProductVariantByPid($where,$fields="*")
     {
         return (new WishWaitUploadProductVariant())->alias('v')
                 ->join('wish_wait_upload_product p','v.pid=p.id','LEFT')
                 ->join('wish_account a','p.accountid=a.id','LEFT')
                 ->field($fields)->where($where)->select();
     }
     /**
      * 拼装商品相册
      * @param type $images
      * @param type $main_image
      * @return type
      */
     public static  function dealImages($images=[],$main_image='')
     {
        $extra_images  = explode('|', $images);

        if($main_image)
        {
            array_unshift($extra_images,$main_image);
        }

        $return=[];
        foreach ($extra_images as $key => $img)
        {
             $return[$key]['path'] = $img;
        }
        return $return;
     }

     /**
     * @node 获取商品和sku信息
     * @access public
     * @param array $where 查询条件
     * @param string $id 产品id
     * @return array
     */

    public static function getProductVariant( $where,$id,$status='')
    {

         $model = new WishWaitUploadProduct;
         $field='id,is_promoted,uid,goods_id,main_image,tags,accountid,name,cron_time,parent_sku,brand,upc,warehouse,transport_property,
            is_virtual_send';
         $data = $model->field($field)->with(['info'])->where($where)->find();

         if(!empty($data))
         {
            $data = $data->toArray();

            $data['tort_flag'] = GoodsTortDescription::where('goods_id',$data['goods_id'])->value('id') ? 1 : 0;
            $data['transport_property']=(new \app\goods\service\GoodsHelp())->getProTransPropertiesTxt($data['transport_property']);//物流属性转文本-pan
            $data['original_images']=$data['info']['original_images'];
            $data['extra_images']=$data['info']['extra_images'];
            $data['description']=$data['info']['description'];
            $data['landing_page_url'] = $data['info']['landing_page_url'];
            if(isset($data['accountid']))
            {
                $accoundInfo = (new WishAccount())->field('code account_code,account_name')->where(['id'=>$data['accountid']])->find();
                $data['account_code']=$accoundInfo['account_code']?$accoundInfo['account_code']:'';
                $data['account_name']=$accoundInfo['account_name']?$accoundInfo['account_name']:'';
            }
            if($data['uid'])
            {
            	$user = (new User())->field('id,realname')->where('id','=',$data['uid'])->find();
            }else{
	            $user ='';
            }

            $skuImages=[];
            if($status=='copy')
            {
                if(isset($data['goods_id']) && $data['goods_id'])
                {
                    $goods_id = $data['goods_id'];
                    $productImages = GoodsImage::getPublishImages($goods_id,3);
                    $images=$productImages['spuImages'];
                    $skuImages = $productImages['skuImages'];
                }else{
                    $images=[];
                }
            }else{
                if(!empty($data['original_images']))   //原始图片不为空则优先采用原始图片
                {
                    $images=self::dealImages($data['original_images']);
                }elseif(!empty ($data['extra_images'])){
                    $images=self::dealImages($data['extra_images'],$data['main_image']);
                }else{
                    $images=[];
                }
            }

            if($data['tags'])
            {
                $tags = explode(',',$data['tags']) ;
                if(is_array($tags))
                {
                    $newTags = [];
                    foreach ($tags as $key => $tag)
                    {
                        $newTags[$key]['name']=$tag;
                    }
                }
            } else {
                $newTags=[];
            }


            $map['pid']=array('eq',$id);

            $variant = WishWaitUploadProductVariant::all($map);
            /*
            if($variant && count($variant)>1)
            {
                
                if(is_array($variant))
                {
                    foreach($variant as $key=>$v)
                    {
                        unset($variant[$key]['add_time']);
                        unset($variant[$key]['message']);
                        unset($variant[$key]['code']);
                        unset($variant[$key]['run_time']);
                        unset($variant[$key]['enable']);
                        unset($variant[$key]['lock_variant']);
                        unset($variant[$key]['update_message']);
                        unset($variant[$key]['sell_status']);        
                        $variant[$key]['id'] = $v['vid'];
                        //$variant[$key]['initSize'] = $v['size'];
                        //$variant[$key]['initColor'] = $v['color'];
                        //$variant[$key]['accountid'] = $accountid;
                    }
                }
            }else{
                $key=0;
                if(count($variant) == 1 && ($variant[$key]['color'] || $variant[$key]['size'])) //如果只有一个sku,判断是否颜色和尺寸是否有值
                {
                    unset($variant[$key]['add_time']);
                    unset($variant[$key]['message']);
                    unset($variant[$key]['code']);
                    unset($variant[$key]['run_time']);
                    unset($variant[$key]['enable']);
                    unset($variant[$key]['lock_variant']);
                    unset($variant[$key]['update_message']);
                    unset($variant[$key]['sell_status']);
                    //$variant = $variant[$key];
                }else{
                    $variant=[];
                }
            }*/

            if($variant && $status=='copy' && $skuImages)
            {
                $variant = GoodsImage::replaceSkuImage($variant,$skuImages,3,'sku_id');
                $variant = GoodsImage::ObjToArray($variant);
            }

            foreach ($variant as &$item){
                $sku_images=[];
                if(isset($item['sku_id']) && $item['sku_id']){
                    $sku_images = GoodsGallery::where(['sku_id'=>$item['sku_id'],'is_default'=>1])->select();
                }
                $item['d_imgs']=$sku_images;
            }


            $price = (new WishWaitUploadProductVariant())->where(['pid'=>$id])->max('price');

            $shipping = (new WishWaitUploadProductVariant())->where(['pid'=>$id])->max('shipping');

            $shipping_time = (new WishWaitUploadProductVariant())->field('shipping_time')->where(['pid'=>$id])->find();

            $inventory = (new WishWaitUploadProductVariant())->where(['pid'=>$id])->max('inventory');

            $msrp = (new WishWaitUploadProductVariant())->where(['pid'=>$id])->max('msrp');

            $cost = (new WishWaitUploadProductVariant())->where(['pid'=>$id])->max('cost');

            $weight = (new WishWaitUploadProductVariant())->where(['pid'=>$id])->max('weight');

            $data['cost']=$cost;
            $data['weight']=$weight;
            $data['base_url']=Cache::store('configParams')->getConfig('innerPicUrl')['value'].DS;
            $data['vars'] = array(
                array(
                   'accountid'=>$data['accountid']?$data['accountid']:'',
                   'account_code'=>$data['account_code']?$data['account_code']:'',
                   'account_name'=>$data['account_name']?$data['account_name']:'',
                   'realname'=>$user?$user['realname']:'',
                   'name'=>$data['name'],
                   'inventory'=>$inventory,
                   'msrp'=>$msrp,
                   'price'=>$price,
                   'tags'=>$newTags ,
                   'shipping'=>$shipping,
                   'shipping_time'=>$shipping_time['shipping_time'],
                   'cron_time'=>$data['cron_time']?$data['cron_time']:'',
                   'description'=>$data['description'],
                   'images'=> $images,
                   'variant'=>$variant,
                    'is_virtual_send' => $data['is_virtual_send'],
               ),
           );
            unset($data['name']);
            unset($data['is_promoted']);
            unset($data['number_saves']);
            unset($data['review_status']);
            unset($data['number_sold']);
            unset($data['last_updated']);
            unset($data['tags']);
            unset($data['cron_time']);
            unset($data['description']);
            unset($data['extra_images']);
            unset($data['main_image']);
            unset($data['wish_Express_Countries']);
            unset($data['accountid']);
            unset($data['all_country_shipping']);
            unset($data['is_virtual_send']);
             //$data['vars']= WishWaitUploadProductVariant::all($map);
            if($data['warehouse']=='null'){
                $data['warehouse']='';
            }

         }else{
             $data=[];
         }
         return $data;
    }

    /**
     * @node 获取wish账号信息
     * @access public
     * @param array $where
     * @return array
     */
     public static function getAccount($where=array(),$fields="*")
     {
        $model = new WishAccount();
        return $model->field($fields)->where($where)->find();
     }
     /**
      * @node 判断是否符合product_id规则
      * @access public
      * @param string $product_id
      * @return boolean true|false
      */
    public static function checkProductId($product_id='')
    {
        if($product_id)
        {
            if(preg_match("/^[a-z\d]*$/i",$product_id))
            {
                return true;
            }else{
                return false;
            }
        }else{
            return false;
        }
    }

     /**
     * @node 检索wish产品信息
     * @access public
     * @param int $accountid 账号id
     * @param string $product_id 产品id
     * @return  array
     */
    public static function retrieveProduct($accountid,$product_id)
    {
        $api = new WishApi();

        $type='Product';

        $accountinfo =  (new WishAccount())->where(['id'=>$accountid])->find();

        if($accountinfo && $product_id)
        {
            $post['id'] = $product_id;

            $post['access_token'] = $accountinfo['access_token'];
            $post['refresh_token'] = $accountinfo['refresh_token'];
            $post['client_id'] = $accountinfo['client_id'];
            $post['client_secret'] = $accountinfo['client_secret'];

            $service = $api::instance($post)->loader($type);

            $res = $service->retrieveProductCurl($post);

            if($res['state'] && isset($res['data']['Product']))
            {

                //----------增加一拉一下物流信息-pan
                $response = $service->getAllShipping(['id'=>$product_id,'access_token'=>$post['access_token']]);
                if($response['state']==true && $response['code']==0)
                {
                    $shipping_prices = $response['data']['ProductCountryAllShipping']['shipping_prices'];
                    $res['data']['Product']['all_country_shipping']=json_encode($shipping_prices); //插入物流信息
                    $res['data']['Product']['shipping_status']=1;
                }
                //---------------

                $product = self::dealRetrieverResponse($res['data']['Product']);

                return ['state'=>true,'data'=>$product];
            }else{
                return ['state'=>false];
            }
        }

    }

    /**
     * @node 处理检索的产品信息
     * @access public
     * @param array $product
     * @return array
     */
    public static function dealRetrieverResponse($product)
    {
        $return=[];
        $skus=[];
        if($product && is_array($product))
        {

            //物流信息-pan
            if (isset($product['all_country_shipping']))
            {
                $return['all_country_shipping']=$product['all_country_shipping'];
                $return['shipping_status']=$product['shipping_status'];
            }


            $return['product_id']=isset($product['id'])?$product['id']:'';

            $return['name']=isset($product['name'])?$product['name']:'';

            $return['description']=isset($product['description'])? $product['description']:'';

            $return['number_saves']=isset($product['number_saves'])?$product['number_saves']:'';

            $return['number_sold']=isset($product['number_sold'])?$product['number_sold']:'';

            $return['parent_sku']=isset($product['parent_sku'])?$product['parent_sku']:'';

            $return['upc']=isset($product['upc'])?$product['upc']:'';

            $return['landing_page_url']=isset($product['landing_page_url'])?$product['landing_page_url']:'';

            if(isset($product['variants']))
            {
                $variants = $product['variants'];

                foreach ($variants as $k =>$V)
                {
                    $variant =$V['Variant'];

                    $skus[$k]['variant_id']=isset($variant['id'])?$variant['id']:'';

                    $skus[$k]['product_id']=isset($variant['product_id'])?$variant['product_id']:'';

                    $skus[$k]['sku']=isset($variant['sku'])?$variant['sku']:'';

                    $skus[$k]['main_image']=isset($variant['main_image'])?$variant['main_image']:'';

                    $skus[$k]['size']=isset($variant['size'])?$variant['size']:'';

                    $skus[$k]['color']=isset($variant['color'])?$variant['color']:'';

                    $skus[$k]['msrp']=isset($variant['msrp'])?$variant['msrp']:0;

                    $skus[$k]['cost']=isset($variant['cost'])?$variant['cost']:0;

                    $skus[$k]['price']=isset($variant['price'])?$variant['price']:0;

                    $skus[$k]['shipping']=isset($variant['shipping'])?$variant['shipping']:'';

                    $skus[$k]['inventory']=isset($variant['inventory'])?$variant['inventory']:0;

                    $skus[$k]['shipping_time']=isset($variant['shipping_time'])?$variant['shipping_time']:'';

                    $skus[$k]['enabled']=$variant['enabled']=='True'?1:0;
                }
            }

            if(isset($product['is_promoted']))
            {
                $is_promoted=$product['is_promoted'];
            }else{
                $is_promoted='False';
            }

            $return['is_promoted']=$is_promoted=='True'?1:0;

            $return['review_status']=isset($product['review_status'])?$product['review_status']:'';

            $return['review_status'] = self::deal_review_status( $return['review_status']);

            $return['counterfeit_reasons']=isset($product['counterfeit_reasons'])?$product['counterfeit_reasons']:'';

            $return['main_image']=isset($product['main_image'])?$product['main_image']:'';

            $return['extra_images']=isset($product['extra_images'])?$product['extra_images']:'';
             //待处理
            if(isset($product['tags']) && is_array($product['tags']))
            {
                $tags = self::deal_tags($product['tags']);
            }else{
                $tags='';
            }
            $return['tags']=$tags;

            $return['brand']=isset($product['brand_name'])?$product['brand_name']:'';

            $return['last_updated']=str2time(isset($product['last_updated'])?$product['last_updated']:'');

            $return['date_uploaded']=str2time(isset($product['date_uploaded'])?$product['date_uploaded']:'');

            $return['warning_id']=isset($product['warning_id'])?$product['warning_id']:'';

            $return['wish_express_countries']=isset($product['wish_express_country_codes_str'])?$product['wish_express_country_codes_str']:'';

            return ['product'=>$return,'skus'=>$skus];

        }
    }

    public static function deal_review_status($str)
    {
        if($str=='approved') //approved
        {
            $res = 1;
        }elseif($str=='rejected'){ //rejected
            $res = 2;
        }elseif($str=='pending'){ //pending
            $res = 3;
        }else{
            $res=0;
        }
        return $res;
    }
    /**
     * 处理检索商品tags
     * @param array $tags
     * @return array
     */
    public static function deal_tags(array $tags)
    {
        $newTag = '';
        foreach($tags as $tag)
        {
            $newTag=$newTag.$tag['Tag']['name'].',';
        }
        $newTag = substr($newTag,0, strlen($newTag)-1);
        return $newTag;
    }

}
