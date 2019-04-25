<?php
/**
 * Created by PhpStorm.
 * User: joy
 * Date: 18-1-7
 * Time: 上午9:09
 */

namespace app\publish\service;


use app\common\cache\Cache;
use app\common\exception\JsonErrorException;
use app\common\model\AttributeValue;
use app\common\model\Goods;
use app\common\model\GoodsAttribute;
use app\common\model\GoodsDiscount;
use app\common\model\GoodsSku;
use app\common\model\GoodsTortDescription;
use app\common\model\joom\JoomAccount;
use app\common\model\joom\JoomActionLog;
use app\common\model\joom\JoomAttributeValue;
use app\common\model\joom\JoomProduct;
use app\common\model\joom\JoomProductInfo;
use app\common\model\joom\JoomVariant;
use app\common\model\User;
use app\common\service\Common;
use app\common\service\CommonQueuer;
use app\common\service\Twitter;
use app\common\service\UniqueQueuer;
use app\goods\service\GoodsPublishMapService;
use app\goods\service\GoodsSkuMapService;
use app\common\model\joom\JoomShop;
use app\index\service\DownloadFileService;
use app\index\service\MemberShipService;
use app\listing\service\WishListingHelper;
use app\publish\queue\JoomCombineSkuQueue;
use app\publish\queue\JoomListingUpdateQueue;
use app\publish\queue\JoomQueueJob;
use app\publish\validate\JoomValidate;
use think\Db;
use think\Exception;
use think\exception\DbException;
use think\exception\PDOException;
use app\common\model\GoodsLang;
use app\common\model\GoodsPublishMap;
use app\common\model\joom\JoomShopCategory;
use app\publish\service\GoodsImage;
class JoomService
{
    private $channel_id=7;
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
    ];
    public function dowonload($ids)
    {
        $model = new JoomProduct();
        $products = $model->with(['variants','info'])->whereIn('id',$ids)->select();

        $rows = [];
        foreach ($products as $product){
            $variants = $product['variants'];
            foreach ($variants as $variant){
                $row['parent_sku']=$product['parent_sku'];
                $row['sku']=$variant['sku'];
                $row['name']=$product['name'];
                $row['tags']=$product['tags'];
                $row['msrp']=$variant['msrp'];
                $row['price']=$variant['price'];
                $row['weight']=$variant['weight'];
                $row['shipping']=$variant['shipping'];
                $row['size']=$variant['size'];
                $row['color']=$variant['color'];
                $row['description']=mb_convert_encoding($product['info']['description'],'gb2312','utf-8');
                $row['main_image']=$product['main_image'];
                $row['display_image']=$variant['main_image'];
                $images = explode('|',$product['info']['extra_images']);
                foreach ($images as $k=>$image){
                    if($k<10){
                        $index = $k+1;
                        $row['thumb'.$index] = $image;
                    }
                }
                $rows[$variant['id']]= $row;
            }
        }
        $header = [
            ['title' => '父SKU', 'key' => 'parent_sku', 'width' => 10],
            ['title' => 'SKU', 'key' => 'sku', 'width' => 10],
            ['title' => '标题', 'key' => 'name', 'width' => 35],
            ['title' => '标签', 'key' => 'tags', 'width' => 35],
            ['title' => '厂商建议价', 'key' => 'msrp', 'width' => 10],
            ['title' => '价格', 'key' => 'price', 'width' => 10],
            ['title' => '重量', 'key' => 'weight', 'width' => 10],
            ['title' => '运费', 'key' => 'shipping', 'width' => 10],
            ['title' => '尺寸', 'key' => 'size', 'width' => 10],
            ['title' => '颜色', 'key' => 'color', 'width' => 10],
            ['title' => '描述/详情', 'key' => 'description', 'width' => 40],
            ['title' => '主图', 'key' => 'main_image', 'width' => 20],
            ['title' => '展图', 'key' => 'display_image', 'width' => 20],
            ['title' => 'Extra Image URL 1', 'key' => 'thumb1', 'width' => 20],
            ['title' => 'Extra Image URL 2', 'key' => 'thumb2', 'width' => 20],
            ['title' => 'Extra Image URL 3', 'key' => 'thumb3', 'width' => 20],
            ['title' => 'Extra Image URL 4', 'key' => 'thumb4', 'width' => 20],
            ['title' => 'Extra Image URL 5', 'key' => 'thumb5', 'width' => 20],
            ['title' => 'Extra Image URL 6', 'key' => 'thumb6', 'width' => 20],
            ['title' => 'Extra Image URL 7', 'key' => 'thumb7', 'width' => 20],
            ['title' => 'Extra Image URL 8', 'key' => 'thumb8', 'width' => 20],
            ['title' => 'Extra Image URL 9', 'key' => 'thumb9', 'width' => 20],
            ['title' => 'Extra Image URL 10', 'key' => 'thumb10', 'width' => 20],
        ];
        $userId = Common::getUserInfo()->toArray()['user_id'];
        $file = [
            'name' => (new ProductDownloadService())->createExportFileName($userId,0),
            'path' => 'goods'
        ];
        $ExcelExport = new DownloadFileService();
        return $ExcelExport->exportCsv($rows, $header, $file);
    }
    /**
     * @title 更新商品和变体数据
     * @param $parmas
     * @return array
     */
    public function updateProductAndVariant($parmas)
    {
        try{
            if(isset($parmas['id']) && $parmas['id'])
            {
                $id = $parmas['id'];
                //获取商品刊登状态
                if($this->getPublishStatus($id))
                {
                    $response = $this->updatePublishedProduct($parmas);
                }else{
                    $response = $this->updateUnpublishedProduct($parmas);
                }
                return $response;
            }else{
                throw new JsonErrorException("id非法,找不到对应数据，无法进行更新");
            }

        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }

    /**
     * 更新未刊登成功的商品 信息
     * @param $post
     * @return array
     */
    private function updateUnpublishedProduct($post){
        try{
            if (isset($post['vars'])) {
                $vars = json_decode($post['vars'], true); //每个账号信息
            }else{
                throw new JsonErrorException("数据格式非法");
            }

            $products = []; //产品
            if (is_array($vars))
            {
                foreach ($vars as $k => $var)
                {
                    $products[$k]['id'] = $post['id'];
                    $products[$k]['goods_id'] = $post['goods_id'];  //商品id
                    $products[$k]['account_id'] = $var['account_id']; //账号id
                    $products[$k]['shop_id'] = $var['shop_id']; //账号id
                    $products[$k]['name'] = $var['name']; //刊登标题
                    $products[$k]['main_image'] = $var['images'][0]; //商品主图
                    $products[$k]['description'] = $var['description'];//详情描述
                    $products[$k]['tags'] = $var['tags']; //Tags
                    $products[$k]['parent_sku'] = $post['parent_sku']; //SPU
                    $products[$k]['brand'] = $post['brand']; //品牌
                    $products[$k]['upc'] = $post['upc']; //UPC
                    if (preg_match('/^http(s)?:\\/\\/.+/', $post['landing_page_url'])) {
                        $products[$k]['landing_page_url'] = $post['landing_page_url'];
                    } else {
                        $products[$k]['landing_page_url'] = ''; //商品展示页面
                    }
                    $products[$k]['extra_images'] = implode('|', array_slice($var['images'], 1)); //商品相册a.jpg|b.jpg
                    $products[$k]['original_images'] = implode('|', $var['images']); //商品相册a.jpg|b.jpg
                    $products[$k]['cron_time'] = strtotime($var['cron_time']); //定时刊登
                    $products[$k]['warehouse'] = @$post['warehouse'];
                    $variants = [];
                    //更新时，每个商品的variant都已经存在，不存在variant为空的情况
                    if (isset($var['variant']) && !empty($var['variant']))
                    {
                        $variants = $var['variant'];
                        foreach ($variants as &$v) {
                            $v['add_time'] = time();
                            $v['status'] = 0;
                        }
                    }
                    $products[$k]['variants'] = $variants;
                }
            }else{
                throw new JsonErrorException("数据格式不合法");
            }

            if ($products)
            {
                if (is_array($products))
                {
                    foreach ($products as $p)
                    {
                        Db::startTrans();
                        try{

                            $queue = (string)$p['id'];
                            $variants = $p['variants'];
                            unset($p['variants']);
                            (new JoomProduct())->isUpdate(true)->allowField(true)->save($p);
                            (new JoomProductInfo())->allowField(true)->isUpdate(true)->save($p);
                            foreach ($variants as $variant)
                            {
                                if(isset($variant['id']) && isset($variant['joom_product_id']))
                                {
                                    $map['id'] = ['=', $variant['id']]; //没有sku时，根据pid更新
                                    $map['joom_product_id'] = ['=', $variant['joom_product_id']];
                                    (new JoomVariant())->isUpdate(true)->allowField(true)->save($variant,$map);
                                }else{
                                    (new JoomVariant())->isUpdate(false)->allowField(true)->save($variant);
                                }
                            }

                            $findWhere = [
                                'joom_product_id' => ['=', $queue],
                                'status' => ['<>', 1],
                            ];

                            //如果存在没有刊登成功的加入队列
                            if (JoomVariant::where($findWhere)->find())
                            {
                                JoomVariant::where($findWhere)->update(['status' => 0, 'message' => '', 'run_time' => '']);
                            } else {
                                //设置状态为已更新
                                (new JoomProduct())->update(['lock_product' => 1, 'lock_update' => 1], ['id' => $queue]);
                                (new JoomVariant())->update(['lock_variant' => 1], ['joom_product_id' => $queue]);
                            }

                            Db::commit();
                            if ($p['cron_time'] <= time()) {
                                (new UniqueQueuer(JoomQueueJob::class))->push($queue);
                            } else {
                                (new UniqueQueuer(JoomQueueJob::class))->push($queue,$p['cron_time']);
                            }
                            $return = ['result' => true, 'message' => '更新成功'];
                        }catch (PDOException $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }catch (DbException $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }catch (\Exception $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }
                    }
                }else{
                    throw new JsonErrorException("数据格式非法");
                }
            }else{
                throw new JsonErrorException("数据为空");
            }
            return $return;
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");

        }
    }

    /**
     * 更新刊登成功的商品信息
     * @param $post
     * @return array
     */
    private function updatePublishedProduct($post)
    {
        try{
            if(isset($post['vars']))
            {
                $vars = json_decode($post['vars'],true);
            }else{
                throw new JsonErrorException("数据格式非法");
            }

            $uid = $post['uid'];
            $products =[];
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
                    $products[$k]['variants']=$var['variant'];
                }
            }else{
                throw new JsonErrorException("数据格式非法");
            }

            if($products)
            {
                if(is_array($products))
                {
                    foreach($products as $p)
                    {

                        if( $this->saveJoomProductUpdateField($uid,$p))
                        {
                            JoomProduct::where('id','=',$p['id'])->update(['lock_product'=>1,'lock_update'=>1]);
                        }
                        $variants = $p['variants'];
                        unset($p['variants']);
                        if(is_array($variants) && $variants)
                        {
                            foreach ($variants as $variant)
                            {

                                if(!empty($variant))
                                {
                                    //刊登成功之后添加的sku
                                    if (!isset($variant['id']))
                                    {
                                        $variant['joom_product_id'] = $p['id'];
                                        (new JoomVariant())->allowField(true)->isUpdate(false)->save($variant);
                                    }else{
                                        if($this->saveJoomVariantUpdateField($uid,$variant))
                                        {
                                            JoomVariant::where('id','=',$variant['id'])->update(['lock_variant'=>1]);
                                            JoomProduct::where(['id'=>$variant['joom_product_id']])->update(['lock_update'=>1]);
                                        }
                                    }
                                }
                            }
                        }
                        $return=['result'=>true,'message'=>'更新成功'];
                    }
                }else{
                    throw new JsonErrorException("数据格式非法");
                }
            }else{
                throw new JsonErrorException("数据格式非法");
            }
            return $return;
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
    public function saveJoomProductUpdateField($uid,$product)
    {
        try{
            $fields =['name','tags','brand','upc','main_image','max_quantity'];
            $info=['description','landing_page_url','extra_images',];
            $update=false;
            if(isset($product['id']) && $product['id'])
            {
                foreach ($product as $type=>$value)
                {
                    if(in_array($type,$fields))
                    {
                        $row = JoomProduct::where('id','=',$product['id'])->limit(1)->find();
                    }elseif(in_array($type,$info)){
                        $row = JoomProductInfo::where('id','=',$product['id'])->limit(1)->find();
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

                            if($this->joomActionLog($log,$where))
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
     * 保存发生了变化的数据，记录到action_log
     * @param $uid 用户id
     * @param $variant
     * @return bool
     */
    public function saveJoomVariantUpdateField($uid,$variant)
    {
        try{
            $fields =['combine_sku','sku','inventory','price','shipping','shipping_weight','enabled','size','color','msrp','shipping_time','main_image','warehouse_name','declared_value'];
            $update=false;

            if(isset($variant['id']) && $variant['id'])
            {
                $object = JoomVariant::where('id','=',$variant['id'])->limit(1)->find();

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
                                'id'=>$variant['id'],
                                'combine_sku'=>$variant['combine_sku']
                            ];

                            (new CommonQueuer(JoomCombineSkuQueue::class))->push($queue);
                        }

                        if($this->joomActionLog($log,$where))
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
     * @title 写入修改日志
     * @param array $log
     * @param array $where
     * @return false|int
     * @throws Exception
     */
    public function joomActionLog(array $log,$where=[])
    {
        try{
            $model = new JoomActionLog();

            if($rs = $model->get($where)){
                $id = $rs['id'];
                $return = $model->allowField(true)->isUpdate(true)->save($log, ['id'=>$rs['id']]);
            }else{
                $return =  $model->save($log);
                $id = $model->id;
            }

            $cron_time = isset($log['cron_time'])?strtotime($log['cron_time']):0;
            (new CommonQueuer(JoomListingUpdateQueue::class))->push($id,$cron_time);
            return $return ;
        }catch (Exception $exp){
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }

    /**
     * @title 获取商品是否全部刊登成功，true全部成功,false还有没有成功的
     * @param $id
     * @return bool
     * @throws DbException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function getPublishStatus($id)
    {
        $where=[
            'joom_product_id'=>['=',$id],
            'status'=>['<>',1],
        ];
        $variant = (new JoomVariant())->where($where)->field('status')->find();
        if($variant)
        {
            return false;
        }else{
            return true;
        }
    }

    /**
     * @title 获取提交数据
     * @param $id
     * @param $status
     * @return array|false|\PDOStatement|string|\think\Model
     */
    public function getProductAndVariant($id,$status)
    {
        try{
            $where['id'] = ['eq', $id];
            $model = new JoomProduct();
            $field='dangerous_kind,id,create_id,product_id,name,create_id,goods_id,main_image,tags,shop_id,account_id,cron_time,parent_sku,brand,upc,warehouse';
            $data = $model->field($field)->with(['info','variants'])->where($where)->find();
            $user=[];
            if(!empty($data))
            {
                $data = $data->toArray();
                $data['tort_flag'] = GoodsTortDescription::where('goods_id',$data['goods_id'])->value('id') ? 1 : 0;
                $data['original_images']=$data['info']['original_images'];
                $data['extra_images']=$data['info']['extra_images'];
                $data['description']=$data['info']['description'];
                $data['landing_page_url'] = $data['info']['landing_page_url'];
                $transportProperty = Goods::where(['id'=>$data['goods_id']])->value('transport_property');
//                $data['transport_property'] = (new \app\goods\service\GoodsHelp())->getProTransPropertiesTxt($transportProperty);
                if(isset($data['shop_id']))
                {
                    $shopInfo = (new JoomShop())->field('code,shop_name')->where(['id'=>$data['shop_id']])->find();
                    $shopCode=$shopInfo['code']?$shopInfo['code']:'';
                    $shopName=$shopInfo['shop_name']?$shopInfo['shop_name']:'';

                    $user = (new MemberShipService())->member($this->channel_id,$data['shop_id'],'sales');

                }else{
                    $shopCode=$shopName='';
                }

                if(isset($data['account_id']))
                {
                    $accountInfo = (new JoomAccount())->field('account_name')->where(['id'=>$data['account_id']])->find();
                    $accountName=$accountInfo['account_name']?$accountInfo['account_name']:'';
                }else{
                    $accountName='';
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
                        $images=WishListingHelper::dealImages($data['original_images']);
                    }elseif(!empty ($data['extra_images'])){
                        $images=WishListingHelper::dealImages($data['extra_images'],$data['main_image']);
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

                foreach ($data['variants'] as $k=>$v){
                    $data['variants'][$k]['declared_value'] = empty($v['declared_value']) ? '0' : $v['declared_value'];
                }

                $variants =$data['variants'];


                if($variants && $status=='copy' && $skuImages)
                {
                    $variants = GoodsImage::replaceSkuImage($variants,$skuImages,$this->channel_id,'sku_id');
                    $variants = GoodsImage::ObjToArray($variants);
                }

                $goods = Cache::store('goods')->getGoodsInfo($data['goods_id']);

                $user = Cache::store('user')->getOneUser($data['create_id']);

                $price = max(array_column($variants,'price'));

                $shipping = max(array_column($variants,'shipping'));

                $shipping_time = $this->getMaxShippingTime((array_column($variants,'shipping_time')));

                $inventory = max(array_column($variants,'inventory'));

                $msrp = max(array_column($variants,'msrp'));

                $cost = max(array_column($variants,'cost'));

                $weight = max(array_column($variants,'weight'));

                $data['zh_name']=isset($goods['name'])?$goods['name']:'';
                $data['spu']=isset($goods['spu'])?$goods['spu']:'';
                $data['cost']=$cost;
                $data['weight']=$weight;
                $data['base_url']=Cache::store('configParams')->getConfig('innerPicUrl')['value'].DS;
                $data['vars'] = array(
                    array(
                        'account_id'=>$data['account_id']?$data['account_id']:0,
                        'shop_code'=>$shopCode,
                        'shop_id'=>$data['shop_id'],
                        'shop_code'=>$shopCode,
                        'shop_name'=>$shopName,
                        'realname'=>isset($user['realname'])?$user['realname']:'',
                        'parent_spu'=>$data['parent_sku'],
                        'account_name'=>$accountName,
                        'realname'=>$user?$user['realname']:'',
                        'name'=>$data['name'],
                        'inventory'=>$inventory,
                        'msrp'=>$msrp,
                        'price'=>$price,
                        'tags'=>$newTags ,
                        'shipping'=>$shipping,
                        'shipping_time'=>$shipping_time,
                        'cron_time'=>$data['cron_time']?$data['cron_time']:'',
                        'description'=>$data['description'],
                        'images'=> $images,
                        'variant'=>$variants,
                        'dangerous_kind'=>$data['dangerous_kind'],
                        'transport_property' => (new \app\goods\service\GoodsHelp())->getProTransPropertiesTxt($transportProperty)
                    ),
                );
                unset($data['info']);
                unset($data['variants']);
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

                if($data['warehouse']=='null'){
                    $data['warehouse']='';
                }

            }else{
                $data=[];
            }
            return $data;
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }catch (\Throwable $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");

        }

    }

    /**
     * @param $shipping_times
     * @return string
     */
    public function getMaxShippingTime($shipping_times)
    {

        try{
            $minShippingTimes = [];
            $maxShippingTimes = [];
            foreach ($shipping_times as $k=>$shipping_time)
            {
                if (empty($shipping_time) || strpos($shipping_time,'-') === false) {
                    continue;
                }
                list($min,$max)=explode("-",$shipping_time);
                $minShippingTimes[] = $min;
                $maxShippingTimes[] = $max;

//                if($k==0)
//                {
//                    $min_shipping_time=$min;
//                    $max_shipping_time=$max;
//                }else{
//                    if($max>$max_shipping_time)
//                    {
//                        $max_shipping_time=$max;
//                    }
//                    if($min<$min_shipping_time)
//                    {
//                        $min_shipping_time=$min;
//                    }
//                }
            }

            return min(empty($minShippingTimes)?[0]:$minShippingTimes)."-".max(empty($maxShippingTimes)?[0]:$maxShippingTimes);
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");

        }

    }
    /**
     * @title 获取未刊登列表
     * @param $params
     * @param int $page
     * @param int $pageSize
     * @param string $fields
     * @return array
     * @throws DbException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUnpublishList($params,$page=1,$pageSize=10,$fields="*")
    {
        $where=$join=[];

        $fields='distinct(m.goods_id),g.category_id,m.spu,g.thumb,g.name,g.publish_time,g.packing_en_name';

        $where['channel']=['eq',$this->channel_id];

        $where['m.platform_sale']=['IN',array(0,1)];
        $where['g.sales_status'] = ['IN', array(1, 4)];

        $post = $params;
        if(1){
            if ($post['snType'] && $post['snText']) {
                $txt = json_decode($post['snText'],true);
                if (is_null($txt)) {//不是json格式，按字符串处理
                    $txt = [$post['snText']];
                }
                $count = count($txt);
                switch ($post['snType']) {
                    case 'spu':
                        if ($count == 1) {
                            $goodsIds  = Goods::where('spu','like',$txt[0].'%')->column('id');
                        } elseif ($count > 1) {
                            $goodsIds = Goods::whereIn('spu',$txt)->column('id');
                        }
                        $where['m.goods_id'] = empty($goodsIds) ? ['exp', 'is null'] : ['in', $goodsIds];
                        break;
                    case 'name':
                        if ($count == 1) {
                            $where['name'] = ['like', $txt[0].'%'];
                        } elseif ($count > 1) {
                            $where['name'] = ['in', $txt];
                        }
                        break;
                    case 'sku':
                        if ($count == 1) {
                            $goodsIds = GoodsSku::distinct(true)->where('sku','like',$txt[0].'%')
                                ->column('goods_id');
                        } elseif ($count > 1) {
                            $goodsIds = GoodsSku::whereIn('sku',$txt)->distinct(true)->column('goods_id');
                        }
                        $where['m.goods_id'] = empty($goodsIds) ? ['exp', 'is null'] : ['in', $goodsIds];
                        break;
                }
            }
//            if( isset($post['snType']) && $post['snType']=='spu' && $post['snText'])
//            {
//                $spus = json_decode($post['snText'],true);
//                if (is_null($spus)) {//不是json格式，按字符串处理
//                    $tmp = ['like', $post['snText'].'%'];
//                } elseif (count($spus)==1) {
//                    $tmp = ['like',$post['snText'][0].'%'];
//                } elseif (count($spus)>1) {
//                    $tmp = ['in',$spus];
//                }
//                if (!empty($tmp)) {
//                    $where['m.'.$post['snType']] = tmp;
//                }
//            }
//
//            if( isset($post['snType']) && $post['snType']=='name' && $post['snText'])
//            {
//                $names = json_decode($post['snText'],true);
//                if (is_null($spus)) {//不是json格式，按字符串处理
//                    $tmp = ['like', $post['snText'].'%'];
//                } elseif (count($spus)==1) {
//                    $tmp = ['like',$post['snText'][0].'%'];
//                } elseif (count($spus)>1) {
//                    $tmp = ['in',$spus];
//                }
//                if (!empty($tmp)) {
//                    $where['m.'.$post['snType']] = tmp;
//                }
//                $where['name'] = array('like','%'.$post['snText'].'%');
//            }
//
//            if( isset($post['snType']) && $post['snType']=='id' && $post['snText'])
//            {
//                $where['m.goods_id'] = array('eq',$post['snText']);
//            }
//            if (isset($post['snType']) && $post['snType'] == 'sku' && $post['snText']) {
//                $where['sku'] = array('like', $post['snText'] . '%');
//                $join[] = ['goods_sku gs','gs.goods_id=g.id'];
//            }
        }else{
            if( isset($post['snType']) && $post['snType']=='spu' && $post['snText'])
            {
                $where['m.'.$post['snType']] = array('=',$post['snText']);
            }

            if( isset($post['snType']) && $post['snType']=='name' && $post['snText'])
            {
                $where['name'] = array('=',$post['snText']);
            }

            if( isset($post['snType']) && $post['snType']=='id' && $post['snText'])
            {
                $where['m.goods_id'] = array('=',$post['snText']);
            }
            if (isset($post['snType']) && $post['snType'] == 'sku' && $post['snText']) {
                $where['sku'] = array('=',$post['snText']);
                $join[] = ['goods_sku gs','gs.goods_id=g.id'];
            }
        }


        if (isset($post['category_id']) && $post['category_id'] )
        {

            $category_id = (int)$post['category_id'];

            $categories = CommonService::getSelfAndChilds($category_id);

            $where['g.category_id'] = array('IN', $categories);
        }



        $authCategoryArray=[];
        if(isset($post['account_id']) && is_numeric($post['account_id']) && is_numeric($post['shop_id']))
        {
            //$where['m.publish_status$."'.$post['account_id'].'"'] = ['=',0];
            $map = " JSON_SEARCH(m.publish_status,'one', ".$post['shop_id'].") IS NULL ";

            $where1=[
                'joom_shop_id'=>['=',$post['shop_id']],
                'joom_account_id'=>['=',$post['account_id']],
            ];

            $authCategorys = JoomShopCategory::where($where1)->find();

            if(empty($authCategorys))
            {
                $data=[
                    'code'=>400,'message'=>'帐号还没有绑定平台分类与本地分类关系，请先绑定',
                ];
                return $data;
            }

            $authCategory = JoomShopCategory::where($where1)->select();

            $authCategoryArray = array_column($authCategory,'category_id');

        }else{
            $map=[];
        }


        if(!empty($authCategoryArray))
        {

            if(empty($join))
            {
                $count = (new GoodsPublishMap())->alias('m')->join('goods g','m.goods_id=g.id','LEFT')
                    ->where($where)->where($map)->whereIn('m.category_id',$authCategoryArray)
                    ->count();
                $data = (new GoodsPublishMap())->order('publish_time desc')->field($fields)->alias('m')->join('goods g','m.goods_id=g.id','LEFT')
                    ->where($where)->where($map)->whereIn('m.category_id',$authCategoryArray)
                    ->page($page,$pageSize)->select();
            }else{
                $count = (new GoodsPublishMap())->alias('m')
                    ->join('goods g','m.goods_id=g.id','LEFT')
                    ->join($join)
                    ->where($where)->where($map)->whereIn('m.category_id',$authCategoryArray)
                    ->count();
                $data = (new GoodsPublishMap())->order('publish_time desc')->field($fields)->alias('m')
                    ->join('goods g','m.goods_id=g.id','LEFT')
                    ->join($join)
                    ->where($where)->where($map)->whereIn('m.category_id',$authCategoryArray)
                    ->page($page,$pageSize)->select();
            }

        }else{

            if(empty($join))
            {
                $count = (new GoodsPublishMap())->alias('m')->join('goods g','m.goods_id=g.id','LEFT')
                    ->where($where)->where($map)
                    ->count();

                $data = (new GoodsPublishMap())->order('publish_time desc')->field($fields)->alias('m')->join('goods g','m.goods_id=g.id','LEFT')
                    ->where($where)->where($map)
                    ->page($page,$pageSize)->select();
            }else{
                $count = (new GoodsPublishMap())->alias('m')
                    ->join('goods g','m.goods_id=g.id','LEFT')
                    ->join($join)
                    ->where($where)->where($map)
                    ->count();

                $data = (new GoodsPublishMap())->order('publish_time desc')->field($fields)->alias('m')
                    ->join('goods g','m.goods_id=g.id','LEFT')
                    ->join($join)
                    ->where($where)->where($map)
                    ->page($page,$pageSize)->select();
            }

        }
        $goodsModel = new Goods();
        $discountIds = [];
        if ($data) {
            $data = collection($data)->toArray();
            $goodsIds = array_column($data,'goods_id');
            $enTitles = GoodsLang::whereIn('goods_id',$goodsIds)->where('lang_id',2)->column('title','goods_id');
            $tortGoodsIds = GoodsTortDescription::distinct(true)->whereIn('goods_id',$goodsIds)->column('goods_id');

            //获取降价ids
            $discountIds = (new GoodsDiscount())->whereIn('goods_id',$goodsIds)->where('valid_time','<',time())->where('over_time','>',time())->where('status',1)->column('goods_id');
        }
        foreach ($data as $k=> &$d)
        {
            $d['id'] = $d['goods_id'];
            $d['tort_flag'] = in_array($d['goods_id'],$tortGoodsIds) ? 1 : 0;
            $category = $goodsModel->getCategoryAttr("",$d);
            if($category)
            {
                $d['category'] = $category;
            }else{

                $d['category'] = '';
            }
            $lang = GoodsLang::where(['goods_id'=>$d['goods_id'],'lang_id'=>2])->field('title')->find();

            if($lang)
            {
                $d['packing_en_name'] = $lang['title'];
            }

            $d['is_discounting'] = in_array($data[$k]['goods_id'],$discountIds) ? 1 : 0;

            $d['thumb'] = empty($d['thumb'])?'':\app\goods\service\GoodsImage::getThumbPath($d['thumb'],60,60);
        }
        return ['data'=>$data,'count'=>$count,'page'=>$page,'pageSize'=>$pageSize,'code'=>200];
    }

    /**
     * @node 插入数据
     * @access public
     * @param array $post
     * @param array $post
     * @return int|string
     * @throws \Exception
     */
    public function insertData(array $post)
    {
        try {
            if (isset($post['vars']))
            {
                $vars = json_decode($post['vars'], true); //每个账号信息
            }
            if (isset($post['warehouse']) && $post['warehouse'] == 'null') {
                $post['warehouse'] = '';
            }
            $goodsSkuMapModel = new GoodsSkuMapService();
            $spu = $post['parent_sku'];
            $timestamp = time();
            $products=[];
            if (is_array($vars))
            {
                foreach ($vars as $k => $var)
                {

                    //$pid = Twitter::instance()->nextId($this->channel_id, $var['account_id']);
                    //$products[$k]['id'] = $pid;
                    $products[$k]['goods_id'] = $post['goods_id'];  //商品id
                    $products[$k]['account_id'] = $var['account_id']; //账号id
                    $products[$k]['shop_id'] = $var['shop_id']; //账号id
                    $products[$k]['create_id'] = $post['uid']; //登录用户id
                    $products[$k]['name'] = $var['name']; //刊登标题
                    $products[$k]['dangerous_kind'] = $var['dangerous_kind']; //刊登标题
                    $products[$k]['main_image'] = $var['images'][0]; //商品主图
                    $products[$k]['description'] = $var['description'];//详情描述
                    $products[$k]['tags'] = $var['tags']; //Tags
                    $products[$k]['parent_sku'] = (new GoodsSkuMapService())->createSku($spu); //SPU
                    $products[$k]['brand'] = $post['brand']; //品牌
                    $products[$k]['upc'] = $post['upc']; //UPC
                    $products[$k]['landing_page_url'] = $post['landing_page_url'] ? @$post['landing_page_url'] : ''; //商品展示页面
                    $products[$k]['extra_images'] = implode('|', array_slice($var['images'], 1)); //商品相册a.jpg|b.jpg
                    $products[$k]['original_images'] = implode('|', $var['images']); //商品原始图片
                    $products[$k]['cron_time'] = strtotime($var['cron_time']); //定时刊登
                    $products[$k]['warehouse'] = $post['warehouse'];
                    $products[$k]['last_updated'] = $timestamp;
                    $products[$k]['application'] = 'rondaful';
                    if(isset($var['variant']) && !empty($var['variant']))
                    {
                        $variants = $var['variant'];

                        foreach ($variants as &$v)
                        {
                            $v['sku_id'] = $v['id'];
                            unset($v['id']);
                            if (isset($v['product_id'])) {
                                unset($v['product_id']);
                            }
                            if (isset($v['variant_id'])) {
                                unset($v['variant_id']);
                            }
                            if (isset($v['enabled'])) {
                                unset($v['enabled']);
                            }

                            //$v['joom_product_id'] = $pid;
                            $v['status'] = 0;
                            if (isset($v['sku_id'])) {
                                $v['sku_id'] = $v['sku_id'];
                            } else {
                                $v['sku_id'] = 0;
                            }

                            if (isset($v['sell_status'])) {
                                $v['sell_status'] = $v['sell_status'];
                            } else {
                                $v['sell_status'] = '';
                            }

                            $v['add_time'] = $timestamp;

//                            $create_sku_code_response = $goodsSkuMapModel->createSku($v['sku']);
//                            if ($create_sku_code_response) {
//                                $v['sku'] = $create_sku_code_response;
//                            }else{
//                                throw new JsonErrorException("生成随机sku编码失败");
//                            }

                            if(isset($v['combine_sku']) && !empty($v['combine_sku']))
                            {
                                $create_sku_code_response = $goodsSkuMapModel->addSkuCodeWithQuantity(['combine_sku'=>$v['combine_sku'],'sku_code' => $v['sku'], 'channel_id' => $this->channel_id, 'account_id' => $var['account_id']], $post['uid']);
                            }else{
                                $create_sku_code_response = $goodsSkuMapModel->addSku(['sku_code' => $v['sku'], 'channel_id' => $this->channel_id, 'account_id' => $var['account_id']], $post['uid']);
                            }

                            if ($create_sku_code_response['result']) {
                                $v['sku'] = $create_sku_code_response['sku_code'];
                            }

                        }
                    }
                    $products[$k]['variants'] = $variants;
                }
            }
            $num = 0;
            if ($products)
            {
                if (is_array($products))
                {
                    foreach ($products as $k => $p)
                    {
                        Db::startTrans();
                        try{
                            $variants = $p['variants'];

                            //$pid =$p['id'];

                            $productModel  = new JoomProduct();

                            $productModel->allowField(true)->save($p);

                            $pid = $productModel->getLastInsID();

                            $p['id']= $pid;

                            (new JoomProductInfo())->allowField(true)->save($p);

                            foreach ($variants as &$variant)
                            {
                                $variant['joom_product_id']=$pid;
                                $variant['original_image_url']=$variant['main_image'];
                            }

                            if (count($variants) == count($variants, 1))
                            {
                                (new JoomVariant())->isUpdate(false)->allowField(true)->save($variants);
                            } else {
                                (new JoomVariant())->isUpdate(false)->allowField(true)->saveAll($variants);
                            }

                            Db::commit();
                            $queue = (string)$pid;
                            //非定时刊登
                            if ($p['cron_time'] <= time())
                            {
                                (new UniqueQueuer(JoomQueueJob::class))->push($queue);
                            } else {
                                (new UniqueQueuer(JoomQueueJob::class))->push($queue, $p['cron_time']);
                            }

                            $num = $num + 1;
                            if ($pid)
                            {
                                $update = $this->ProductStat((new JoomVariant()), ['joom_product_id' => $pid]);
                                if ($update)
                                {
                                    (new JoomProduct())->update($update, ['id' => $pid]);
                                }
                            }
                            GoodsPublishMapService::update($this->channel_id, $spu, $p['shop_id'],1,$p['account_id']);
                        }catch (PDOException $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line{$exp->getLine()};Message:{$exp->getMessage()}");
                        }catch (DbException $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line{$exp->getLine()};Message:{$exp->getMessage()}");
                        }catch (Exception $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line{$exp->getLine()};Message:{$exp->getMessage()}");
                        }
                    }
                }
            }
            if($num==0)
            {
                $num='未知异常';
            }
            return $num;

        } catch (Exception $exp) {
            throw new JsonErrorException("File:{$exp->getFile()};Line{$exp->getLine()};Message:{$exp->getMessage()}");
        }
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
     * @node 验证提交数据合法性
     * @access public
     * @param array $post
     * @return string
     */
    public function validatePost($post = array())
    {
        $validate = new JoomValidate();

        $error = $validate->checkData($post);

        if ($error)
        {
            return $error;
        }

        if (isset($post['vars']))
        {

            $vars = json_decode($post['vars'], true);
            if (is_array($vars) && !empty($vars))
            {
                $error = $validate->checkVars($vars, 'var');
                if ($error)
                {
                    return $error;
                }
            }
        }
    }


    /**
     * @node 验证提交数据合法性
     * @access public
     * @param array $post
     * @return string
     */
    public function validateUpdate($post = array())
    {
        $validate = new JoomValidate();

        $error = $validate->checkData($post);

        if ($error)
        {
            return $error;
        }

        if (isset($post['vars']))
        {

            $vars = json_decode($post['vars'], true);
            if (is_array($vars) && !empty($vars))
            {
                $error = $validate->checkVars($vars, 'var');
                if ($error)
                {
                    return $error;
                }
            }
        }
    }

    /**
     * @title 获取刊登数据
     * @param $goods_id
     * @return array
     * @throws DbException
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getGoodsData($goods_id)
    {
        $baseInfo = Cache::store('goods')->getGoodsInfo($goods_id);

        if (empty($baseInfo))
        {
            throw new JsonErrorException("商品信息不存在");
        }
        $help = new GoodsHelp();
        $baseInfo['brand']    = $baseInfo['brand_id'] ? $help->getBrandById($baseInfo['brand_id']) : '';

        $baseInfo['warehouse']= $baseInfo['warehouse_id'] ? $help->getWarehouseById($baseInfo['warehouse_id']) : '';
        $baseInfo['warehouse_type'] = $baseInfo['warehouse_id'] ? $help->getWarehouseTypeById($baseInfo['warehouse_id']) : '';
        $baseInfo['transport_property'] = $baseInfo['transport_property'] ?
            (new \app\goods\service\GoodsHelp())->getProTransPropertiesTxt($baseInfo['transport_property']) : '';

        $skus = GoodsSku::where('goods_id','=',$goods_id)->whereIn('status',[1,4])->order('id ASC')->select();

        if($skus)
        {
            $skus = $this->getSkuAttr($skus,$goods_id);
        }

        $images = \app\publish\service\GoodsImage::getPublishImages($goods_id,$this->channel_id);

        $galleries = $images['spuImages'];

        $skuImages = $images['skuImages'];

        if($skus && $skuImages)
        {
            $skus = \app\publish\service\GoodsImage::replaceSkuImage($skus,$skuImages,$this->channel_id);
        }


        if (empty($galleries)) {
            $galleries = [];
        }

        $titleDesc = (new WishHelper())->getProductDescription($goods_id, 2);

        if ($titleDesc) {
            $name = @$titleDesc['title'];
            $description = @$titleDesc['description'];

            $sellingPoints = json_decode($titleDesc['selling_point'], true);
            if (!empty($sellingPoints)) {
                $spStr = 'Bullet Points:<br>';
                $i = 1;
                foreach ($sellingPoints as $sellingPoint) {
                    if (empty($sellingPoint)) {
                        continue;
                    }
                    $spStr .= (string)$i.'. '.$sellingPoint.'<br>';
                    $i++;
                }
                $spStr .= '<br>';
                $description = $spStr.$description;
            }


            $description = str_replace('<br>', "\n", $description);
            $description = str_replace('<br />', "\n", $description);
            $description = str_replace('&nbsp;', " ", $description);

            if ($titleDesc['tags']) {
                $tags = explode(',', $titleDesc['tags']);
                $newTags =  (new WishHelper())->arrayAddKey($tags, 'name');
            } else {
                $newTags = [];
            }

        } else {
            $description = '';
            $name = '';
            $newTags = [];
        }

        $vars = array(
            array(
                'account_id' => '',
                'account_code' => '',
                'account_name' => '',
                'name' => $name,
                'inventory' => '',
                'msrp' => 0,
                'price' => $baseInfo['retail_price'],
                'tags' => $newTags,
                'shipping' => '',
                'shipping_time' => '',
                'cron_time' => '',
                'description' => $description,
                'images' => $galleries,
                'shipping_weight'=>$baseInfo['weight'],
                'variant' => $skus,
                'dangerous_kind' => 'notDangerous',
                'transport_property' => $baseInfo['transport_property'],
            ),
        );

        $data = array(
            'goods_id' => $goods_id,
            'tort_flag' => GoodsTortDescription::where('goods_id',$goods_id)->value('id') ? 1 : 0,
            'zh_name' => $baseInfo['name'],
            'parent_sku' => $baseInfo['spu'],
            'spu' => $baseInfo['spu'],
            'brand' => $baseInfo['brand'],
            'upc' => '',
            'landing_page_url' => $baseInfo['source_url'] ? $baseInfo['source_url'] : '',
            'warehouse' => $baseInfo['warehouse'],
            'warehouse_type' => $baseInfo['warehouse_type'],
            'cost' => $baseInfo['cost_price'],
            'weight' => $baseInfo['weight'],
            'source' => 'original',
            'vars' => $vars,
            'channel_id'=>7,
            'base_url'=> Cache::store('configParams')->getConfig('innerPicUrl')['value'].DS
        );
        return $data;

    }

    /**
     * @node 获取sku属性
     * @access public
     * @param array $skus sku数组
     * @param ind $goods_id 商品id
     * @param $skus
     * @param $goods_id
     * @return mixed
     * @throws Exception
     */
    public function getSkuAttr($skus, $goods_id)
    {
        try{
            if ($skus && is_array($skus))
            {

                foreach ($skus as $k => &$sku)
                {
                    $sku = is_object($sku)?$sku->toArray():$sku;

                    $sku['combine_sku'] = $sku['sku']."*1";

                    $sku_attributes = json_decode($sku['sku_attributes'], true);

                    $sku['size'] = '';

                    foreach ($sku_attributes as $attribute_id => $attribute_value_id)
                    {
                        list($attr, $attr_id) = explode('_', $attribute_id);//$attr_id //属性名

                        $attrKeyVal = (new AttributeValue())->field('a.value,a.code vcode,b.code')->alias('a')->join('attribute b', 'a.attribute_id=b.id', 'LEFT')
                            ->where(['a.id' => $attribute_value_id, 'a.attribute_id' => $attr_id])->find();

                        if ($attrKeyVal)
                        {
                            //如果类型是type获取style,则取goods_attribute表里的alias
                            if($attrKeyVal['code']=='type' || $attrKeyVal['code']=='style')
                            {
                                $where=[
                                    'goods_id'=>['=',$sku['goods_id']],
                                    'attribute_id'=>['=',$attr_id],
                                    'value_id'=>['=',$attribute_value_id]
                                ];

                                $goodsAttribute = GoodsAttribute::where($where)->find();

                                if($goodsAttribute)
                                {
                                    if(strlen($sku['size']))
                                    {
                                        $sku['size']= $sku['size'].' & '.$goodsAttribute['alias'];
                                    }else{
                                        $sku['size']= $sku['size'].$goodsAttribute['alias'];
                                    }

                                }
                            } elseif ($attrKeyVal['code'] == 'color') {

                                //匹配wish platform color values
                                $joomColorValue = (new JoomAttributeValue())->where(['code' => $attrKeyVal['value'],'joom_attribute_id'=>1])->find();
                                if ($joomColorValue) {
                                    $sku['color'] = $joomColorValue['code'];
                                } else {
                                    $sku['color'] = $attrKeyVal['vcode'];
                                }

                            } else {
                                if (count($sku_attributes) > 2)
                                {
                                    if(strlen($sku['size'])>0)
                                    {
                                        $sku['size'] = $sku['size'] . ' & ' . $attrKeyVal['value'];
                                    }else{
                                        $sku['size'] = $attrKeyVal['value'];
                                    }

                                } else {
                                    $sku['size'] = $attrKeyVal['value'];
                                }
                            }
                        }
                    }

                    $sku['inventory'] = WishHelper::skuInventory($sku, $goods_id) ? self::skuInventory($sku, $goods_id) : 0;
                    $sku['price'] = 0;
                    $sku['shipping_time'] = '';
                    $sku['shipping'] = 0;
                    $sku['msrp'] = 0;
                    $sku['shipping_weight'] = $sku['weight']/1000;

                    $sku['cost_price'] = round($sku['cost_price'],2);

                    unset($sku['goods_id']);
                    unset($sku['alias_sku']);
                    unset($sku['retail_price']);
                    unset($sku['create_time']);
                    unset($sku['update_time']);
                    unset($sku['sku_attributes']);
                    if (!isset($sku['color'])) {
                        $sku['color'] = '';
                    }

                    if (!isset($sku['size'])) {
                        $sku['size'] = '';
                    }
                    $sku['refer_color']=$sku['color']?$sku['color']:'无';
                    $sku['refer_size']=$sku['size']?$sku['size']:'无';
                }
            }
            return $skus;
        }catch (Exception $exp){
            throw new Exception($exp->getMessage());
        }

    }

    /**
     * 根据SKU获取刊登过该SKU的销售员
     * @param $skuId
     * @return array
     */
    public static function getSalesmenBySkuId($skuId)
    {
        try {
            //根据sku获取对应的goods id
            $goodsIds = GoodsSku::where('id',$skuId)->value('goods_id');
            //根据goods id获取已刊登listing的销售员
            $wh['goods_id'] = $goodsIds;
            $wh['enabled'] = 1;
            $salesmenIds = JoomProduct::distinct(true)->where($wh)->column('create_id');
            return $salesmenIds;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * @title 根据商品获取刊登过该商品的销售员
     * @param $goodsId
     * @return array
     */
    public static function getSalesmenByGoodsId($goodsId)
    {
        try {
            $wh['goods_id'] = $goodsId;
            $wh['enabled'] = 1;
            $salesmenIds = JoomProduct::distinct(true)->where($wh)->column('create_id');
            return $salesmenIds;
        } catch (\Exception $e) {
            return [];
        }
    }
}