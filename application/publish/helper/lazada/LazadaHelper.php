<?php
/**
 * Created by PhpStorm.
 * User: wlw2533
 * Date: 2018/8/29
 * Time: 10:50
 */

namespace app\publish\helper\lazada;

use app\common\cache\Cache;
use app\common\model\GoodsSku;
use app\common\model\lazada\LazadaAccount;
use app\common\model\lazada\LazadaAttribute;
use app\common\model\lazada\LazadaCategory;
use app\common\model\lazada\LazadaProduct;
use app\common\model\lazada\LazadaProductAttribute;
use app\common\model\lazada\LazadaProductInfo;
use app\common\model\lazada\LazadaSite;
use app\common\model\lazada\LazadaVariant;
use app\common\model\lazada\LazadaVariantImage;
use app\common\model\shopee\ShopeeLogistic;
use app\common\model\shopee\ShopeeProduct;
use app\common\model\shopee\ShopeeProductInfo;
use app\common\model\shopee\ShopeeVariant;
use app\common\service\ChannelAccountConst;
use app\common\service\CommonQueuer;
use app\common\service\UniqueQueuer;
use app\goods\service\GoodsSkuMapService;
use app\goods\service\GoodsPublishMapService;
use app\goods\service\GoodsImage;
use app\publish\queue\ShopeeGetItemDetailQueue;
use service\lazada\LazadaApi;
use think\Db;
use think\Exception;
use app\publish\helper\lazada\LazadaUtil;
use think\exception\DbException;
use think\exception\PDOException;
use app\common\model\lazada\LazadaBrand;
use app\common\service\Common as CommonService;

class LazadaHelper
{
    public const PUBLISH_STATUS = [//本地记录的刊登状态
        'fail' => -1,//刊登失败
        'noStatus' => 0,//未刊登
        'inPublishQueue' => 1,//刊登队列中
        'publishing' => 2,//刊登中
        'success' => 3,//刊登成功
        'inUpdateQueue' => 4,//更新队列中
        'updating' => 5,//更新中
        'failUpdate' => 6,//更新失败
        'offLine' => 7,//下架
    ];
    public const API_TYPE = [//接口操作类型
        'create' => 1,//创建
        'update' => 2,//更新
        'remove' => 3//删除
    ];
    public const VARIANT_STATUS = [//线上状态
        //1:active 2:inactive 3:deleted 4:image-missing 5:pending 6:rejected 7:sold-out',
        'active' => 1,
        'live' => 1,
        'inactive' => 2,
        'deleted' => 3,
        'image-missing' => 4,
        'pending' => 5,
        'rejected' => 6,
        'sold-out' => 7,
    ];

    public const STATIC_ATTRIBUTE_FILED = [//静态属性字段
        'Status',
        'quantity',
        'tax_class',
        '_compatible_variation_',
        'Images',
        'SellerSku',
        'ShopSku',
        'special_time_format',
        'package_content',
        'Url',
        'package_width',
        'special_to_time',
        'special_from_time',
        'package_height',
        'special_price',
        'price',
        'package_length',
        'special_from_date',
        'package_weight',
        'Available',
        'SkuId',
        'special_to_date',
    ];


    /**
     * 根据站点同步分类
     * @param $country
     * @return bool|string
     */
    public function syncCategoriesByCountry($siteId, $siteCode)
    {
        try {
            //获取认证信息和参数
            $config = $this->getAuthorization(0, $siteCode);
            if (!$config) {
                return false;
            }
            $config['site'] = strtolower($config['site']);
            //获取请求的url
            $serviceUrl = LazadaUtil::getSiteLink($config['site']);
            $config['service_url'] = $serviceUrl;
            $response = LazadaApi::instance($config)->handler('Category')->getCategoryByCountry();
            try {
                $message = $this->checkResponse($response);
                if (is_string($message)) {
                    return false;
                }
                $categories = LazadaUtil::categoryTreeToArr2($response['data']);
            } catch (Exception $e) {
                echo $e->getFile().'---------------'.$e->getLine().'=========='.$e->getMessage();
            }
            //处理数据
            $categoryIds = [];
            foreach ($categories as $k => &$v) {
                $v['site_id'] = $siteId;
                $categoryIds[$k] = intval($v['category_id']);
            }
            //获取旧分类信息
            $oldCategoryIds = LazadaCategory::where(['site_id'=>$siteId])->column('category_id');
            //更新数据库
            $data = [
                'new_ids' => $categoryIds,
                'old_ids' => $oldCategoryIds,
                'new_items' => $categories,
                'item' => 'category_id'
            ];
            $where = [
                'del_wh' => ['site_id'=>$siteId],
                'update_wh' => ['site_id'=>$siteId]
            ];
            $res = $this->curdDb($data,LazadaCategory::class,$where);

            return $res;
        } catch (Exception $e) {
            return $e->getMessage();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 同步分类属性
     * @param $country
     * @param $categoryId
     * @return bool|string
     */
    public function syncAttributes($siteId, $siteCode, $categoryId)
    {
        try {
            //获取认证信息和参数
            $config = $this->getAuthorization(0, $siteCode);
            $config['site'] = strtolower($config['site']);//获取请求的url
            $serviceUrl = LazadaUtil::getSiteLink($config['site']);
            $config['service_url'] = $serviceUrl;
            $response = LazadaApi::instance($config)->handler('Attributes')->getAttributesByCategoryId($categoryId);
            $message = $this->checkResponse($response);
            if (is_string($message)) {
                return false;
            }
//            $res = '{"data":[{"is_sale_prop":0,"name":"name","input_type":"text","options":[],"is_mandatory":1,"attribute_type":"normal","label":"Name"},{"is_sale_prop":0,"name":"short_description","input_type":"richText","options":[],"is_mandatory":1,"attribute_type":"normal","label":"Short Description"},{"is_sale_prop":0,"name":"description","input_type":"richText","options":[],"is_mandatory":0,"attribute_type":"normal","label":"Long Description (Lorikeet)"},{"is_sale_prop":0,"name":"video","input_type":"text","options":[],"is_mandatory":0,"attribute_type":"normal","label":"Video URL"},{"is_sale_prop":0,"name":"brand","input_type":"singleSelect","options":[],"is_mandatory":1,"attribute_type":"normal","label":"Brand"},{"is_sale_prop":0,"name":"model","input_type":"text","options":[],"is_mandatory":1,"attribute_type":"normal","label":"Model"},{"is_sale_prop":1,"name":"color_family","input_type":"multiEnumInput","options":[{"name":"Antique White"},{"name":"Apricot"},{"name":"Aqua"},{"name":"Army Green"},{"name":"Avocado"},{"name":"Beige"},{"name":"Black"},{"name":"Blue"},{"name":"Blue Gray"},{"name":"Blueberry"},{"name":"Blush Pink"},{"name":"Bronze"},{"name":"Brown"},{"name":"Burgundy"},{"name":"Cacao"},{"name":"Camel"},{"name":"Champagne"},{"name":"Champagne Pink"},{"name":"Charcoal"},{"name":"Cherry"},{"name":"Chestnut"},{"name":"Chili Red"},{"name":"Chocolate"},{"name":"Cinnamon"},{"name":"Clear"},{"name":"Coffee"},{"name":"Cream"},{"name":"Dark Ash"},{"name":"Dark Brown"},{"name":"Dark Grey"},{"name":"Dark blue"},{"name":"Deep Black"},{"name":"Deep Blue"},{"name":"Deep Gray"},{"name":"Deep green"},{"name":"Emerald Green"},{"name":"Floral"},{"name":"Fluorescent Green"},{"name":"Fluorescent Yellow"},{"name":"Fuchsia"},{"name":"Galaxy"},{"name":"Glitter Black"},{"name":"Glitter Blue"},{"name":"Glow Yellow"},{"name":"Gold"},{"name":"Green"},{"name":"Grey"},{"name":"Hotpink"},{"name":"Ivory"},{"name":"Jade"},{"name":"Jet Black"},{"name":"Khaki"},{"name":"Lake Blue"},{"name":"Lavender"},{"name":"Lemon Yellow"},{"name":"Light Ash"},{"name":"Light Black"},{"name":"Light Grey"},{"name":"Light blue"},{"name":"Light green"},{"name":"Light yellow"},{"name":"Magenta"},{"name":"Mahogany"},{"name":"Mango"},{"name":"Maroon"},{"name":"Matte Black"},{"name":"Metallic Cherry"},{"name":"Metallic Lilac"},{"name":"Metallic Teal"},{"name":"Mint"},{"name":"Multicolor"},{"name":"Navy Blue"},{"name":"Neo Bright"},{"name":"Neon"},{"name":"Neutral"},{"name":"Not Specified"},{"name":"Ochre Brown"},{"name":"Off White"},{"name":"Olive"},{"name":"Orange"},{"name":"Orchid Grey"},{"name":"Peach"},{"name":"Peanut"},{"name":"Pink"},{"name":"Purple"},{"name":"Rainbow"},{"name":"Red"},{"name":"Rose"},{"name":"Rose Gold"},{"name":"Rose Red"},{"name":"Sand"},{"name":"Sand Brown"},{"name":"Silver"},{"name":"Space Grey"},{"name":"Tan"},{"name":"Teal"},{"name":"Turquoise"},{"name":"Violet"},{"name":"Watermelon red"},{"name":"White"},{"name":"Wither Black"},{"name":"Yellow"},{"name":"…"}],"is_mandatory":1,"attribute_type":"sku","label":"Color Family"},{"is_sale_prop":0,"name":"__images__","input_type":"img","options":[],"is_mandatory":0,"attribute_type":"sku","label":"Images"},{"is_sale_prop":0,"name":"color_thumbnail","input_type":"img","options":[],"is_mandatory":0,"attribute_type":"sku","label":"Color thumbnail"},{"is_sale_prop":0,"name":"waterproof","input_type":"singleSelect","options":[{"name":"Not waterproof"},{"name":"Waterproof"}],"is_mandatory":0,"attribute_type":"normal","label":"Waterproof"},{"is_sale_prop":0,"name":"material","input_type":"singleSelect","options":[{"name":"ABS"},{"name":"Acetate"},{"name":"Acrylic"},{"name":"Alloy"},{"name":"Aluminium"},{"name":"Artificial Leather"},{"name":"Brass"},{"name":"Bronze"},{"name":"Bullion"},{"name":"Butter"},{"name":"Canvas"},{"name":"Carbon fiber"},{"name":"Ceramic"},{"name":"Clay"},{"name":"Cloth"},{"name":"Coconut Wax"},{"name":"Copper"},{"name":"Cordura"},{"name":"Cotton"},{"name":"Crystal"},{"name":"EVA"},{"name":"Fabric"},{"name":"Feathers"},{"name":"Fur"},{"name":"GLASS"},{"name":"GOLD"},{"name":"Hematite"},{"name":"Iron"},{"name":"Latex"},{"name":"Leather"},{"name":"Leather/PU Leather"},{"name":"Marble"},{"name":"Metal"},{"name":"Mirror"},{"name":"Mixed"},{"name":"Neoprene"},{"name":"Nickel"},{"name":"Nylon"},{"name":"OTHER"},{"name":"Obsidian"},{"name":"Opal"},{"name":"PP board"},{"name":"PVC"},{"name":"PVC Tarpaulin"},{"name":"Paper"},{"name":"Pearl/faux pearl"},{"name":"Plastic"},{"name":"Polycarbonate"},{"name":"Polyester"},{"name":"Polyester Spandex"},{"name":"Polypropylene"},{"name":"Polyurethane"},{"name":"Polyvinyl Chloride (PVC)"},{"name":"Porcelain"},{"name":"Puzzle"},{"name":"Quartz"},{"name":"Resin"},{"name":"Rhinestone"},{"name":"Rhodium"},{"name":"Rubber"},{"name":"STONE"},{"name":"Semi-precious Stone"},{"name":"Sheet Metal"},{"name":"Shell"},{"name":"Silicone"},{"name":"Silver"},{"name":"Stainless Steel"},{"name":"Stering Silver 925"},{"name":"Surgical Steel"},{"name":"Tin Alloy"},{"name":"Titanium"},{"name":"Titanium Steel"},{"name":"Tungsten"},{"name":"Urea-formaldehyde"},{"name":"Wood"},{"name":"Zinc Alloy"},{"name":"Zircon"},{"name":"sunbrella fabric"}],"is_mandatory":0,"attribute_type":"normal","label":"Material"},{"is_sale_prop":0,"name":"recommended_gender","input_type":"singleSelect","options":[{"name":"Boys"},{"name":"Female"},{"name":"Girls"},{"name":"Male"},{"name":"OTHER"},{"name":"Unisex"}],"is_mandatory":0,"attribute_type":"normal","label":"Recommended Gender"},{"is_sale_prop":0,"name":"warranty_type","input_type":"singleSelect","options":[{"name":"International Manufacturer Warranty"},{"name":"International Seller Warranty"},{"name":"Lazada refund warranty only"},{"name":"Local Manufacturer Warranty"},{"name":"Local Supplier Refund Warranty"},{"name":"Local Supplier Warranty"},{"name":"No Warranty"}],"is_mandatory":0,"attribute_type":"normal","label":"Warranty Type"},{"is_sale_prop":0,"name":"package_content","input_type":"text","options":[],"is_mandatory":0,"attribute_type":"sku","label":"What\'s in the box"},{"is_sale_prop":0,"name":"SellerSku","input_type":"text","options":[],"is_mandatory":1,"attribute_type":"sku","label":"SellerSKU"},{"is_sale_prop":0,"name":"warranty","input_type":"singleSelect","options":[{"name":"1 Month"},{"name":"1 Year"},{"name":"10 Months"},{"name":"10 Years"},{"name":"11 Months"},{"name":"14 Days"},{"name":"18 Months"},{"name":"2 Months"},{"name":"2 Years"},{"name":"25 Years"},{"name":"3 Months"},{"name":"3 Years"},{"name":"30 years"},{"name":"4 Months"},{"name":"4 Years"},{"name":"5 Months"},{"name":"5 Years"},{"name":"6 Months"},{"name":"6 Years"},{"name":"7 Days"},{"name":"7 Months"},{"name":"7 Years"},{"name":"8 Months"},{"name":"9 Months"},{"name":"Life Time Warranty"}],"is_mandatory":0,"attribute_type":"normal","label":"Warranty Period"},{"is_sale_prop":0,"name":"product_warranty","input_type":"text","options":[],"is_mandatory":0,"attribute_type":"normal","label":"Warranty Policy"},{"is_sale_prop":0,"name":"quantity","input_type":"numeric","options":[],"is_mandatory":0,"attribute_type":"sku","label":"Quantity"},{"is_sale_prop":0,"name":"price","input_type":"numeric","options":[],"is_mandatory":1,"attribute_type":"sku","label":"Price"},{"is_sale_prop":0,"name":"delivery_option_standard","input_type":"singleSelect","options":[{"name":"No"},{"name":"Yes"}],"is_mandatory":0,"attribute_type":"normal","label":"Delivery Option Standard"},{"is_sale_prop":0,"name":"special_price","input_type":"numeric","options":[{"name":"default"}],"is_mandatory":0,"attribute_type":"sku","label":"Special Price"},{"is_sale_prop":0,"name":"special_from_date","input_type":"date","options":[],"is_mandatory":0,"attribute_type":"sku","label":"Start date of promotion"},{"is_sale_prop":0,"name":"special_to_date","input_type":"date","options":[],"is_mandatory":0,"attribute_type":"sku","label":"End date of promotion"},{"is_sale_prop":0,"name":"package_weight","input_type":"numeric","options":[],"is_mandatory":1,"attribute_type":"sku","label":"Package Weight (kg)"},{"is_sale_prop":0,"name":"package_length","input_type":"numeric","options":[],"is_mandatory":1,"attribute_type":"sku","label":"Package Length (cm)"},{"is_sale_prop":0,"name":"package_width","input_type":"numeric","options":[],"is_mandatory":1,"attribute_type":"sku","label":"Package Width (cm)"},{"is_sale_prop":0,"name":"package_height","input_type":"numeric","options":[],"is_mandatory":1,"attribute_type":"sku","label":"Package Height (cm)"},{"is_sale_prop":0,"name":"tax_class","input_type":"singleSelect","options":[{"name":"default"}],"is_mandatory":0,"attribute_type":"sku","label":"Taxes"},{"is_sale_prop":0,"name":"min_delivery_time","input_type":"numeric","options":[],"is_mandatory":0,"attribute_type":"sku","label":"Shipping time (min days)"},{"is_sale_prop":0,"name":"max_delivery_time","input_type":"numeric","options":[],"is_mandatory":0,"attribute_type":"sku","label":"Shipping time (max days)"},{"is_sale_prop":0,"name":"Hazmat","input_type":"multiSelect","options":[{"name":"Battery"},{"name":"Flammable"},{"name":"Liquid"},{"name":"None"}],"is_mandatory":0,"attribute_type":"normal","label":"Dangerous Goods"}],"code":"0","request_id":"0baa047715548847664957405"}';
//            $response  = json_decode($res, true);
            //处理数据
            $newAttributes = [];
            $newAttributeIds = [];
            $attributes = $response['data'];
//            print_r($attributes);
            foreach ($attributes as $k => $attribute) {
                //保存一张sku属性键缓存表用于同步product时提取sku属性
//                Cache::store('LazadaItem')->setAttributesNameIndex($attribute['name'], $attribute['label']);
                $attribute['site_id'] = $siteId;
                $attribute['category_id'] = $categoryId;
                $attribute['is_mandatory'] = empty($attribute['is_mandatory']) ? 0 : 1;
                $attribute['is_sale_prop'] = empty($attribute['is_sale_prop']) ? 0 : 1;
                $attribute['attribute_name'] = $attribute['name'];
                $attribute['attribute_label'] = $attribute['label'];
                $attribute['options'] = isset($attribute['options']) ? json_encode($attribute['options']) : json_encode([]);
                $newAttributeIds[] = $attribute['name'];
                unset($attribute['name']);
                unset($attribute['label']);
                $newAttributes[$k] = $attribute;
            }
            //获取旧属性
            $wh['category_id'] = $categoryId;
            $wh['site_id'] = $siteId;
            $oldAttributeIds = LazadaAttribute::where($wh)->column('attribute_name');
            //更新数据库
            $data = [
                'new_ids' => $newAttributeIds,
                'old_ids' => $oldAttributeIds,
                'new_items' => $newAttributes,
                'item' => 'attribute_name'
            ];
            $where = [
                'del_wh' => $wh,
                'update_wh' => $wh
            ];

            $res = $this->curdDb($data,LazadaAttribute::class,$where);
            return $res;
        } catch (Exception $e) {
            return $e->getMessage();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param $siteId
     * @param $siteCode
     * @param $categoryId
     */
    public function syncListing($accountId, $page, $pageSize, $updateTime = '')
    {
        //获取认证信息和参数
        $config = $this->getAuthorization($accountId);
        if (!$config) {
            throw new Exception('账号信息不存在或已禁用');
        }
        $config['site'] = strtolower($config['site']);
        //获取请求的url
        $serviceUrl = LazadaUtil::getSiteLink($config['site']);
        $config['service_url'] = $serviceUrl;
        $params['update_after'] = $updateTime;
        $params['page'] = $page;
        $params['page_size'] = $pageSize;
        $response = LazadaApi::instance($config)->handler('Item')->syncListing($params);
        $message = $this->checkResponse($response);
        if (is_string($message)) {
            throw new Exception($message);
        }
        return $response['data'];
    }


    /**
     * @param $siteId
     * @param $siteCode
     * @param $categoryId
     */
    public function syncItemInformation($accountId, $itemId)
    {
        //获取认证信息和参数
        $config = $this->getAuthorization($accountId);
        if (!$config) {
            throw new Exception('账号信息异常');
        }
        $config['site'] = strtolower($config['site']);
        //获取请求的url
        $serviceUrl = LazadaUtil::getSiteLink($config['site']);
        $config['service_url'] = $serviceUrl;
        $response = LazadaApi::instance($config)->handler('Item')->syncItemInformation($itemId);
        $message = $this->checkResponse($response);
        if (is_string($message)) {
            throw new Exception($message);
        }
        if (isset($response['data']) && $response['data']) {
//            DB::startTrans();
//            try {
                //拼接数据
                $response = $response['data'];
//            print_r($response);
                //产品数据
                $productData = $productAttributeData = $productInfoData = $variantData = [];
                $productData['item_id']     = $response['item_id'];
                $productData['account_id']  = $accountId;
                $productData['category_id'] = $response['primary_category'];
                $productData['name']        = $response['attributes']['name'];
                unset($response['attributes']['name']);
                if (isset($response['AssociatedSku'])) {
                    $productData['item_sku'] = $response['AssociatedSku'];
                }
                if (count($response['skus']) > 1) {
                    $productData['has_variation'] = 1;
                } else {
                    $productData['has_variation'] = 0;
                }
                $productModel = new LazadaProduct();
                $product = $productModel->where(['item_id' => $response['item_id']])->find();
                if (isset($response['skus'])) {
                    foreach ($response['skus'] as $sku => $val) {
                        if ($val['Status'] == 'active') { //只要有一个sku 在线，则视为product在线
                            $productData['publish_status'] = 2;
                            break;
                        }
                    }
                }
                if ($product) {
                    $pid = $product['id'];
                    $productData['id'] = $product['id'];
                    $productModel->save($productData, ['id' => $product['id']]);
                } else {
                    $productModel->save($productData);
                    $pid = $productModel->getLastInsID();
                }
                //产品详情
                $productInfoData['pid'] = $pid;
                if (isset($response['attributes']['description'])) {
                    $productInfoData['description'] = $response['attributes']['description'];
                    unset($response['attributes']['description']);
                }
                if (isset($response['attributes']['description_en'])) {
                    $productInfoData['description_en'] = $response['attributes']['description_en'];
                    unset($response['attributes']['description_en']);
                }
                if (isset($response['attributes']['short_description'])) {
                    $productInfoData['short_description'] = $response['attributes']['short_description'];
                    unset($response['attributes']['short_description']);
                }
                if (isset($response['attributes']['short_description_en'])) {
                    $productInfoData['short_description_en'] = $response['attributes']['short_description_en'];
                    unset($response['attributes']['short_description_en']);
                }
                $productInfoModel = new LazadaProductInfo();
                $productInfo = $productInfoModel->where(['pid' => $pid])->find();
                if ($productInfo) {
                    $productInfoModel->save($productInfoData, ['pid' => $pid]);
                } else {
                    $productInfoModel->save($productInfoData);
                }
                //产品属性
                $productAttributeModel = new LazadaProductAttribute();
                $productAttributes = $productAttributeModel->where(['pid' => $pid])->column('attr_name, id');
                foreach ($response['attributes'] as $k=>$v) {
                    if ($productAttributes) {
                        $productAttributeData[$k]['id'] = $productAttributes[$k];
                    } else {
                        $productAttributeData[$k]['pid'] = $pid;
                    }
                    $productAttributeData[$k]['attr_name'] = $k;
                    $productAttributeData[$k]['attr_value'] = $v;
                }
                $productAttributeModel->saveAll($productAttributeData);
                //变体
                $variantModel = new LazadaVariant();
                $variantAttributes = $variantModel->where(['pid' => $pid])->column('variation_sku, id');
                foreach ($response['skus'] as $kk=>$vv) {
                    if ($variantAttributes) {
                        $variantData[$vv['SellerSku']]['id'] = $variantAttributes[$vv['SellerSku']];
                    } else {
                        $variantData[$vv['SellerSku']]['pid'] = $pid;
                    }
                    $variantData[$vv['SellerSku']]['item_id'] = $itemId;
                    $variantData[$vv['SellerSku']]['status']  = self::VARIANT_STATUS[$vv['Status']];
                    $variantData[$vv['SellerSku']]['variation_sku'] = isset($vv['SellerSku']) ? $vv['SellerSku'] : '';
                    $variantData[$vv['SellerSku']]['original_price'] = isset($vv['price']) ? $vv['price'] : 0;
                    $variantData[$vv['SellerSku']]['price'] = isset($vv['special_price']) ? $vv['special_price'] : 0;
                    $variantData[$vv['SellerSku']]['image_url'] = isset($vv['Url']) ? $vv['Url'] : '';
                    $variantData[$vv['SellerSku']]['quantity'] = isset($vv['quantity']) ? $vv['quantity'] : 0;
                    $variantData[$vv['SellerSku']]['package_content'] = isset($vv['package_content']) ? $vv['package_content'] : '';
                    $variantData[$vv['SellerSku']]['package_width']   = isset($vv['package_width']) ? $vv['package_width'] : 0;
                    $variantData[$vv['SellerSku']]['package_height'] = isset($vv['package_height']) ? $vv['package_height'] : 0;
                    $variantData[$vv['SellerSku']]['package_length'] = isset($vv['package_length']) ? $vv['package_length'] : 0;
                    $variantData[$vv['SellerSku']]['package_weight'] = isset($vv['package_weight']) ? $vv['package_weight'] : 0;
                    $variantData[$vv['SellerSku']]['tax_class']      = isset($vv['tax_class']) ? $vv['tax_class'] : 'default';
                    //检索变体动态属性
                    if (isset($vv['Images'])) {
                        $images[$vv['SellerSku']] = array_filter($vv['Images']);
                        unset($vv['Images']);
                    }
                    //抽取 sku 动态属性
                    foreach ($vv as $skuKey=>$skuVal) {
//                    $cacheAttributeCalue = Cache::store('LazadaItem')->getAttributesValue($skuKey);
                        if (!in_array($skuKey, self::STATIC_ATTRIBUTE_FILED)) {
                            $variantDyncmicAttributes[$vv['SellerSku']][$skuKey] = $skuVal;
                        }
                    }

                }
                $returnVariants = $variantModel->saveAll($variantData);
                //保存sku 动态属性
                if (isset($variantDyncmicAttributes)) {
                    foreach ($returnVariants as $variant) {
                        $vid = $variant['id'];
                        $i = 0;
                        $insertAttributesData = [];
                        foreach ($variantDyncmicAttributes[$variant['variation_sku']] as $dynamicAttr=>$dynamicValue) {
                            $productVariantAttribute = $productAttributeModel->where(['pid' => $pid, 'vid' => $vid, 'attr_name' => $dynamicAttr])->find();
                            if ($productVariantAttribute) {
                                $insertAttributesData[$i]['id'] = $productVariantAttribute['id'];
                            }
                            $insertAttributesData[$i]['pid'] = $pid;
                            $insertAttributesData[$i]['vid'] = $vid;
                            $insertAttributesData[$i]['attr_name'] = $dynamicAttr;
                            $insertAttributesData[$i]['attr_value'] = $dynamicValue;
                            $i++;
                        }
                        $productAttributeModel->saveAll($insertAttributesData);
                    }
                }

                //变体图片
                if (isset($images)) {
                    $j = 0;
                    $variantImageModel = new LazadaVariantImage();
                    foreach ($images as $imgSkuKey=>$imgArr) {
                        $insertImagesData = [];
                        foreach ($imgArr as $imgVal) {
                            //更新
                            if ($variantImageModel->where(['vid' => $returnVariants[$imgSkuKey]['id']])->find()) {
                                LazadaVariantImage::destroy(['vid' => $returnVariants[$imgSkuKey]['id']]);

                            }
                            $insertImagesData[$j]['vid'] = $returnVariants[$imgSkuKey]['id'];
                            $insertImagesData[$j]['image_url'] = $imgVal;
                            $j++;
                        }
                        $variantImageModel->saveAll($insertImagesData);
                    }
                }
//                DB::commit();
//            } catch (PDOException $e) {
//                DB::rollback();
//                throw new Exception($e->getMessage());
//            } catch (DbException $e) {
//                DB::rollback();
//                throw new Exception($e->getMessage());
//            } catch (Exception $e) {
//                DB::rollback();
//                throw new Exception($e->getMessage());
//            }
        }
    }

    /**
     * 保存产品
     * @param $data
     * @param $userId
     * @return int|string
     */
    public function saveProduct($data, $uid, $isUpdate=false)
    {
        try {
            $var = $data['vars'];
            $spu = $data['spu'];
            $userId = $uid;
            $goodsId = $data['goods_id'];
            //更新
            if ($isUpdate) {
                $product['update_id'] = $userId;
            } else {
                //新增，检测该账号下是否已经存在相同产品
                $wh = [
                    'goods_id' => $goodsId,
                    'account_id' => $var['account_id']
                ];
                $existProductId = LazadaProduct::where($wh)->value('id');
                if (!empty($existProductId)) {
                    throw new Exception('账号'.$var['account_code'].'下已存在相同产品，无法在进行创建');
                }
                $product['create_time'] = time();
                $product['create_id'] = $userId;

            }
            $product['goods_id'] = $goodsId;
            $product['category_id'] = $var['category_id'];
            $product['account_id'] = $var['account_id'];
            $product['name'] = $var['name'];
            $product['spu'] = $spu;
            $goodsSkuMapService = new GoodsSkuMapService();
            $product['item_sku'] = $goodsSkuMapService->createSku($spu);
            $product['application'] = 1;
            $product['has_variation'] = 1;
            $product['cron_time'] = empty($var['cron_time']) ? 0 : strtotime($var['cron_time']);

            $productModel = new LazadaProduct();
            //产品详情
            $productInfo['description'] = $var['description'];
            $productInfo['short_description'] = $var['short_description'];
            $productInfoModel = new LazadaProductInfo();
            if ($isUpdate) {
                $pid = $data['id'];
                $product['id'] = $pid;
                $productModel->isUpdate(true)->data($product)->save();
                $productInfoModel->isUpdate(true)->save($productInfo, ['pid' => $pid]);
            } else {
                $productModel->isUpdate(false)->save($product);
                //添加映射
                GoodsPublishMapService::update(ChannelAccountConst::channel_Lazada, $spu, $var['account_id'],1);

                $pid = $productModel->id;
                $productInfo['pid'] = $pid;
                $productInfoModel->isUpdate(false)->save($productInfo);
            }
            //产品动态属性
            $productAttributeData = [];
            $productAttributeModel = new LazadaProductAttribute();
            foreach ($data['vars']['product_attribute'] as $kk=>$vv) {
                $productAttributeData[$kk]['pid'] = $pid;
                $productAttributeData[$kk]['attr_name'] = $kk;
                $productAttributeData[$kk]['attr_value'] = $vv;
                $productAttributeRecord = $productAttributeModel->field('id')->where(['pid' => $pid, 'attr_name' => $kk])->find();
                if ($productAttributeRecord) {
                    $productAttributeData[$kk]['id'] = $productAttributeRecord['id'];
                }

            }
            $productAttributeModel->saveAll($productAttributeData);

            //产品变体和变体动态属性
            $variantModel = new LazadaVariant();
            $variantData = $variantAttributeData = [];
            foreach ($data['vars']['variant'] as $k=>$v) {
                $variantArr['pid'] = $pid;
                $variantArr['sku_id'] = $v['sku_id'];
                $variantArr['sku'] = $v['sku'];
                $variantArr['variation_sku'] = $goodsSkuMapService->createSku($v['sku']);
                $variantArr['original_price'] = $v['original_price'];
                $variantArr['price'] = $v['price'];
                $variantArr['refer_price'] = $v['refer_price'];
                $variantArr['refer_promotion_price'] = $v['refer_promotion_price'];
                $variantArr['package_content'] = isset($v['package_content']) ? $v['package_content'] : '';
                $variantArr['package_width'] = $v['package_width'];
                $variantArr['package_height'] = $v['package_height'];
                $variantArr['package_weight'] = $v['package_weight'];
                $variantArr['package_length'] = $v['package_length'];
                $variantArr['tax_class'] = $v['tax_class'];
                $variantArr['weight'] = isset($v['weight']) ? $v['weight'] : 0;
                $diffVariantArr = array_diff($v, $variantArr);
                $variantAttributeData[$v['sku_id']] = $diffVariantArr;
                if ($isUpdate && $v['vid']) {  // vid 大于0 保证在编辑时，新增sku, 能正确插入
                    $variantArr['id'] = $v['vid'];
                }
                $variantData[$k] = $variantArr;
            }

            $variantReturnData = $variantModel->saveAll($variantData);
            $variantAttributeDataForDb = $variantImageDataForDb = [];
            //变体图片
            $images = json_decode($data['variant_images'], true);
            $lazadaImageModel = new LazadaVariantImage();
            if ($isUpdate) {
                foreach ($variantAttributeData as $key => &$val) {
                    //提取变体属性名
                    $attributeKeyValue = array_slice($val, -1, 1);
                    $attrName = array_keys($attributeKeyValue)[0];
                    $variantAttributeRecord = $productAttributeModel->field('id')->where(['vid' => $val['vid'], 'attr_name' => $attrName])->find();
                    if ($variantAttributeRecord) {
                        $variantAttributeDataForDb[$key]['id'] = $variantAttributeRecord['id'];
                    }
                    $variantAttributeDataForDb[$key]['attr_name'] = $attrName;
                    $variantAttributeDataForDb[$key]['attr_value'] = $attributeKeyValue[$attrName];
                }

                //处理图片
                foreach ($images as $imagKey => $imgVal) {
                    $variantImageRecord = $lazadaImageModel->field('id, vid')->where(['vid' => $imgVal['vid']])->select();
                    foreach ($variantImageRecord as $irk => $irv) {
                        if ($imgVal['id'] == $irv['id']) {
                            $variantImageDataForDb[$irk]['image_url'] = $imgVal['image_url'];
                            $variantImageDataForDb[$irk]['status'] = 1;
                        } else {
                            $variantImageDataForDb[$irk]['status'] = 0;
                        }
                        $variantImageDataForDb[$irk]['id'] = $irv['id'];
                    }
                }
//                print_r($variantData);
//                print_r($variantImageDataForDb);
//                exit;
            } else {
                foreach ($variantReturnData as $key => $val) {

                    if ($variantAttributeData) {
                        $attributeKeyValue = array_slice($variantAttributeData[$val['sku_id']], -1, 1);
                        $attrName = array_keys($attributeKeyValue)[0];
                        $attrValue = $variantAttributeData[$val['sku_id']][$attrName];
                        $variantAttributeDataForDb[$key]['pid'] =  $pid;
                        $variantAttributeDataForDb[$key]['vid'] =  $val['id'];
                        $variantAttributeDataForDb[$key]['attr_name'] = $attrName;
                        $variantAttributeDataForDb[$key]['attr_value'] = $attrValue;
                    }

                    foreach ($images as $imagKey => $imgVal) {
                        if ($val['sku_id'] == $imgVal['vid']) {
                            $variantImageDataForDb[$imagKey]['vid'] = $val['id'];
                            $variantImageDataForDb[$imagKey]['image_url'] = $imgVal['image_url'];
                        }
                    }
                }
            }

            $productAttributeModel->saveAll($variantAttributeDataForDb);

            $lazadaImageModel->saveAll($variantImageDataForDb);
//            print_r($variantData);
//            print_r($VariantAttributeData);
//            print_r($productAttributeData);
//            print_r(json_decode($data['variant_images'], true));
//            exit;
            return $pid;
        } catch (PDOException $e) {
            return $e->getMessage(). '|' . $e->getFile(). '|'. $e->getLine();
        } catch (DbException $e) {
            return $e->getMessage(). '|' . $e->getFile(). '|'. $e->getLine();
        } catch (Exception $e) {
            return $e->getMessage(). '|' . $e->getFile(). '|'. $e->getLine();
        }
    }

    /**
     * 产品刊登
     * @param $id
     */
    public function createProduct($id)
    {

    }









    /**
     * 同步物流
     * @param $accountId
     * @param string $country
     * @return bool
     */
    public function syncLogistics($accountId, $country='')
    {
        try {
            $account = ShopeeAccount::get($accountId);
            if (empty($account)) {
                return '获取账号信息失败';
            }

            $config = [
                'shop_id' => $account->shop_id,
                'partner_id' => $account->partner_id,
                'key' => $account->key
            ];
            $response = ShopeeApi::instance($config)->handler('Logistics')->getLogistics();

            $message = $this->checkResponse($response,'logistics');
            if ($message !== true) {
                return $message;
            }
            //处理数据
            $logistics = $response['logistics'];
            $newLogistics = [];
            $newLogisticIds = [];
            foreach ($logistics as $logistic) {
                $logistic['account_id'] = $accountId;
                $logistic['has_cod'] = $logistic['has_cod'] ? 1 : 0;
                $logistic['enabled'] = $logistic['enabled'] ? 1 : 0;
                $logistic['sizes'] = json_encode($logistic['sizes']);
                $logistic['weight_limits'] = json_encode($logistic['weight_limits']);
                $logistic['item_max_dimension'] = json_encode($logistic['item_max_dimension']);

                if(isset($logistic['preferred'])) {
                    unset($logistic['preferred']);
                }
                $newLogistics[] = $logistic;
                $newLogisticIds[] = $logistic['logistic_id'];
            }
            //获取旧物流
            $oldLogisticIds = ShopeeLogistic::where(['account_id'=>$accountId])->column('logistic_id');

            $data = [
                'new_ids' => $newLogisticIds,
                'old_ids' => $oldLogisticIds,
                'new_items' => $newLogistics,
                'item' => 'logistic_id'
            ];

            $where = [
                'del_wh' => ['account_id' => $accountId],
                'update_wh' => ['account_id' => $accountId]
            ];
            $res = $this->curdDb($data, ShopeeLogistic::class, $where);
            return $res;
        } catch (Exception $e) {
            return $e->getMessage();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 拉取item详情
     * @param $itemId
     * @return bool|string
     */
    public function getItemDetail($itemId, $accountId=0)
    {
        try {
            if ($accountId == 0) {
                $product = ShopeeProduct::field('account_id,id')->where(['item_id' => $itemId])->find();
                if (empty($product)) {
                    throw new Exception('拉取item详情时，根据item id获取产品信息失败');
                }
                $accountId = $product['account_id'];
            }
            $field = 'partner_id,shop_id,key';
            $account = ShopeeAccount::field($field)->where(['id'=>$accountId])->find();
            if (empty($account)) {
                throw new Exception('拉取item详情时，获取账号信息失败');
            }
            $config = $account->toArray();
            $response = ShopeeApi::instance($config)->loader('Item')->getItemDetail(['item_id'=>(int)$itemId]);
            //检查结果是否正确
            $message = $this->checkResponse($response, 'item');
            if ($message !== true) {
                throw new Exception($message);
            }

            //更新
            $itemInfo['item'] = $response['item'];
            $itemInfo['item_id'] = $itemId;
            if ($response['item']['status'] == 'NORMAL') {
                ShopeeProduct::update(['publish_status'=>self::PUBLISH_STATUS['success'], 'publish_message'=>''], ['item_id'=>$itemId]);
            }
            $res = $this->updateProductWithItem($itemInfo, $accountId);
            if ($res !== true) {
                throw new Exception($res);
            }
            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 更新item
     * @param $data
     * @return string
     */
    public function updateItem($data)
    {
        try {
            $var = $data['var'];
            $account = $this->getAuthorization($var['account_id']);
            if (!is_array($account)) {
                throw new Exception($account);
            }

            $item['item_id'] = $var['item_id'];
            $item['category_id'] = $var['category_id'];
            $item['name'] = $var['name'];
            $item['description'] = $var['description'];
            $item['item_sku'] = $var['item_sku'];
            //变体
            if ($var['variant']) {
                $variations = [];
                foreach ($var['variant'] as $variation) {
                    if (empty($variation['variation_id'])) {
                        continue;
                    }
                    $variations[] = [
                        'variation_id' => $variation['variation_id'],
                        'name' => empty($variation['name']) ? $variation['variation_sku'] : $variation['name'],
                        'variation_sku' => $variation['variation_sku']
                    ];
                }
                $item['variations'] = $variations;
            }
            //属性
            $attributes = json_decode($var['attributes'], true);
            if (!empty($attributes)) {
                $itemAttributes = [];
                foreach ($attributes as $k => $attribute) {
                    if (!isset($attribute['attribute_value'])) {
                        continue;
                    }
                    $itemAttributes[] = [
                        'attributes_id' => $attribute['attribute_id'],
                        'value' => $attribute['attribute_value']
                    ];
                }
                $item['attributes'] = $itemAttributes;
            }
            //备货
            if ($var['days_to_ship']>=7 && $var['days_to_ship']<=30) {
                $item['days_to_ship'] = $var['days_to_ship'];
            }
            //批发
            $wholesales = json_decode($var['wholesales'], true);
            if (!empty($wholesales)) {
                $itemWholesales = [];
                foreach ($wholesales as $k => $wholesale) {
                    $itemWholesales[$k]['min'] = (int)$wholesale['min'];
                    $itemWholesales[$k]['max'] = (int)$wholesale['max'];
                    $itemWholesales[$k]['unit_price'] = (float)$wholesale['unit_price'];
                }
                $item['wholesales'] = $itemWholesales;
            }
            //物流
            $logistics = json_decode($var['logistics'], true);
            $item['logistics'] = $logistics;
            $item['weight'] = (float)$var['weight'];
            !empty($var['package_length']) && $item['package_length'] = (int)$var['package_length'];
            !empty($var['package_width']) && $item['package_width'] = (int)$var['package_width'];
            !empty($var['package_height']) && $item['package_height'] = (int)$var['package_height'];

            unset($account['site']);
            unset($account['site_id']);

            $response = ShopeeApi::instance($account)->handler('Item')->updateItem($item);
            $message = $this->checkResponse($response, 'item');
            if ($message !== true) {
                throw new Exception($message);
            }
            ShopeeProduct::update(['publish_status'=>self::PUBLISH_STATUS['success'],'publish_message'=>''], ['id'=>$data['id']]);//更新状态
            //30s后进行一次同步
            $params = [
                'item_id' => $response['item_id'],
                'account_id' => $var['account_id']
            ];
            (new UniqueQueuer(ShopeeGetItemDetailQueue::class))->push($params, 30);
            $res = $this->updateProductWithItem($response, $var['account_id'], $data['id']);
            if ($res !== true) {
                $message = '刊登成功，但是更新本地信息时出现错误，error:'.$res;
                ShopeeProduct::update(['publish_message'=>$message], ['id'=>$data['id']]);
                throw new Exception($res);
            }
            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 根据线上返回的信息，更新本地信息,可以是本地不存在的
     * @param $itemInfo
     * @param $productId
     * @return bool|string
     */
    public function updateProductWithItem($itemInfo, $accountId, $productId=0)
    {
        $dbFlag = false;
        try {
            $status = [
                'NORMAL' => 1,
                'DELETED' => 2,
                'BANNED' => 3
            ];
            $condition = [
                'NEW' => 0,
                'USED' => 1
            ];
            $product = [];
            $productInfo = [];

            if ($productId == 0) {
                $productId = ShopeeProduct::where(['item_id'=>$itemInfo['item_id']])->value('id', 0);
            }

            $item = $itemInfo['item'];
            $product['item_id'] = $itemInfo['item_id'];//item_id必须有
            $product['account_id'] = $accountId;
            isset($item['item_sku']) && $product['item_sku'] = $item['item_sku'];
            isset($item['status']) && $product['status'] = $status[$item['status']];
            isset($item['name']) && $product['name'] = $item['name'];
            isset($item['description']) && $productInfo['description'] = $item['description'];
            isset($item['images']) && $product['images'] = json_encode($item['images']);
            isset($item['currency']) && $product['currency'] = $item['currency'];
            isset($item['has_variation']) && $product['has_variation'] = empty($item['has_variation']) ? 0 : 1;
            isset($item['price']) && $product['price'] = $item['price'];
            isset($item['stock']) && $product['stock'] = $item['stock'];
            isset($item['create_time']) && $product['publish_create_time'] = $item['create_time'];
            isset($item['update_time']) && $product['publish_update_time'] = $item['update_time'];
            isset($item['weight']) && $product['weight'] = $item['weight'];
            isset($item['category_id']) && $product['category_id'] = $item['category_id'];
            isset($item['original_price']) && $product['original_price'] = $item['original_price'];

            if (isset($item['variations'])) {
                $variations = [];
                foreach ($item['variations'] as $k => $variation) {
                    $variation['publish_create_time'] = $variation['create_time'];
                    $variation['publish_update_time'] = $variation['update_time'];
                    if ($productId) {//本地存在，进行更新
                        $variation['update_time'] = time();
                        unset($variation['create_time']);
                    } else {//新增
                        $variation['create_time'] = time();
                        unset($variation['update_time']);
                        $variation['publish_status'] = 1;
                        $variation['status'] = 1;
                    }
                    $variation['item_id'] = $itemInfo['item_id'];
                    $variations[$k] = $variation;
                }
            }
            if (isset($item['attributes'])) {
                $productInfo['attributes'] = json_encode($item['attributes']);
            }
            if (isset($item['logistics'])) {
                $productInfo['logistics'] = json_encode($item['logistics']);
            }
            if (isset($item['wholesales'])) {
                $productInfo['wholesales'] = json_encode($item['wholesales']);
            }
            isset($item['sales']) && $product['sales'] = $item['sales'];
            isset($item['views']) && $product['views'] = $item['views'];
            isset($item['likes']) && $product['likes'] = $item['likes'];
            isset($item['package_length']) && $product['package_length'] = $item['package_length'];
            isset($item['package_width']) && $product['package_width'] = $item['package_width'];
            isset($item['package_height']) && $product['package_height'] = $item['package_height'];
            isset($item['days_to_ship']) && $product['days_to_ship'] = $item['days_to_ship'];
            isset($item['rating_star']) && $product['rating_star'] = $item['rating_star'];
            isset($item['cmt_count']) && $product['cmt_count'] = $item['cmt_count'];
            isset($item['condition']) && $product['condition'] = $condition[$item['condition']];
            $message = [];
            !empty($itemInfo['warning']) && $message['warning'] = $itemInfo['warning'];
            !empty($itemInfo['fail_image']) && $message['fail_image'] = $itemInfo['fail_image'];
            !empty($message) && $productInfo['message'] = json_encode($message);

            //加入一些附加信息
            $product['update_time'] = time();
            $productInfo['item_id'] = $itemInfo['item_id'];
            /*Cache::handler()->hset('shopee.debug.updateitem', $itemInfo['item_id'].'|'.$accountId,
                json_encode($product).'|'.json_encode($productInfo).'|'.json_encode($variations));*/
            Db::startTrans();
            $dbFlag = true;
            if ($productId) {//更新
                //更新主表
                (new ShopeeProduct())->save($product, ['id'=>$productId]);
                //更新信息表
                (new ShopeeProductInfo())->save($productInfo, ['id'=>$productId]);
                //更新变体表
                if (!empty($variations)) {
                    foreach ($variations as $variation) {
                        (new ShopeeVariant())->save($variation,['pid'=>$productId, 'variation_sku'=>$variation['variation_sku']]);
                    }
                }
            } else {//新增
                $productId = (new ShopeeProduct())->insertGetId($product);
                $productInfo['id'] = $productId;
                ShopeeProductInfo::create($productInfo);
                if (!empty($variations)) {
                    foreach ($variations as &$variation) {
                        $variation['pid'] = $productId;
                    }
                    (new ShopeeVariant())->saveAll($variations);
                }

            }
            Db::commit();
            return true;
        } catch (\Exception $e) {
            if ($dbFlag) {
                Db::rollback();
            }
            return $e->getMessage();
        }
    }

    /**
     * 下架item
     * @param $accountId
     * @param $itemId
     * @return bool|string
     */
    public function delItem($accountId, $itemId,$tortId=0)
    {
        $dbFlag = false;
        try {
            $field = 'partner_id,shop_id,key';
            $account = ShopeeAccount::field($field)->where(['id'=>$accountId])->find();
            if (empty($account)) {
                throw new Exception('下架Item时，获取账号信息失败');
            }
            $product = ShopeeProduct::field('goods_id,end_type')->where('item_id',$itemId)->find();
            $config = $account->toArray();
            $response = ShopeeApi::instance($config)->loader('Item')->deleteItem(['item_id'=>$itemId]);
            $message = $this->checkResponse($response, 'item_id');
            if ($message !== true) {
                if ($product['end_type'] == 2) {//侵权下架失败回写
                    $backWriteData = [
                        'goods_id' => $product['goods_id'],
                        'goods_tort_id' => $tortId,
                        'channel_id' => 9,
                        'status' => 2,
                    ];
                    (new UniqueQueuer(\app\goods\queue\GoodsTortListingQueue::class))->push($backWriteData);//回写
                }
                $message = json_encode(['error'=>$message]);
                ShopeeProduct::update(['message'=>$message], ['item_id'=>$itemId]);
                return $message;
            }
            Db::startTrans();
            $dbFlag = true;
            ShopeeProduct::update(['status'=>2,'manual_end_time'=>time()], ['item_id'=>$itemId]);
            ShopeeVariant::update(['status'=>2], ['item_id'=>$itemId]);
            Db::commit();
            if ($product['end_type'] == 2) {//侵权下架失败回写
                $backWriteData = [
                    'goods_id' => $product['goods_id'],
                    'goods_sort_id' => $tortId,
                    'channel_id' => 9,
                    'status' => 1,
                ];
                (new UniqueQueuer(\app\goods\queue\GoodsTortListingQueue::class))->push($backWriteData);//回写
            }
            return true;
        } catch (Exception $e) {
            if ($dbFlag) {
                Db::rollback();
            }
            return $e->getMessage();
        }
    }


    /**
     * 刊登
     * @param $productId
     * @return bool|string
     */
    public function addItem($productId)
    {
        try {
            $product = $this->getProduct($productId);
            if (!is_array($product)) {
                throw new \Exception($product);
            }
            $platformStatus = (new \app\goods\service\GoodsHelp())->getPlatformForChannel($product['product']['goods_id'],9);
            if (!$platformStatus) {
                throw new Exception('商品在该平台已禁止上架');
            }
            $config = $this->getAuthorization($product['product']['account_id']);
            if (!is_array($config)) {
                throw new \Exception($config);
            }

            $item = $this->formatItemData($product);
            print_r($product);exit;
            if (!is_array($item)) {
                throw new \Exception($item);
            }
            unset($config['site_id']);
            unset($config['site']);
            Cache::handler()->hset('shopee.debug.additemsenddata', $productId,
                json_encode($item));
            $response = ShopeeApi::instance($config)->handler('Item')->add($item);
            print_r($response);exit;
            $message = $this->checkResponse($response,'item');
            if ($message !== true) {
                ShopeeProduct::update(['publish_message'=>$message,'publish_status'=>-1], ['id'=>$productId]);
                throw new \Exception($message);
            }

            ShopeeProduct::update(['publish_status'=>3,'publish_message'=>'', 'item_id'=>$response['item_id']], ['id'=>$productId]);
            //刊登成功后push到"SPU上架实时统计队列"
            $skuCount = ShopeeVariant::where('pid',$productId)->count();
            $param = [
                'channel_id' => ChannelAccountConst::channel_Shopee,
                'account_id' => $product['product']['account_id'],
                'shelf_id' => $product['product']['create_id'],
                'goods_id' => $product['product']['goods_id'],
                'times'    => 1, //实时=1
                'quantity' => empty($skuCount) ? 1 : $skuCount,
                'dateline' => time()
            ];
            (new CommonQueuer(\app\report\queue\StatisticByPublishSpuQueue::class))->push($param);
           //30s后进行一次同步
            $params = [
                'item_id' => $response['item_id'],
                'account_id' => $product['product']['account_id']
            ];
            (new UniqueQueuer(ShopeeGetItemDetailQueue::class))->push($params, 30);


            Cache::handler()->hset('shopee.debug.additemresponse', $response['item_id'].'|'.$productId,
                json_encode($response));
            $res = $this->updateProductWithItem($response, $product['product']['account_id'], $productId);
            if ($res !== true) {
                $message = '刊登成功，但是更新本地信息时出现错误，error:'.$res;
                ShopeeProduct::update(['publish_message'=>$message], ['id'=>$product['product']['id']]);
                throw new \Exception($res);
            }
            return true;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 批量删除本地产品
     * @param $productIds
     * @return string
     */
    public function delProducts($productIds)
    {
        $dbFlag = false;
        try {
            $enableDelStatus = [
                ShopeeHelper::PUBLISH_STATUS['fail'],
                ShopeeHelper::PUBLISH_STATUS['noStatus'],
                ShopeeHelper::PUBLISH_STATUS['inPublishQueue'],
                ShopeeHelper::PUBLISH_STATUS['offLine'],
            ];
            $wh['publish_status'] = ['in', $enableDelStatus];
            $wh['id'] = ['in', $productIds];
            $ids = ShopeeProduct::where($wh)->column('id');//过滤不允许删除的
            Db::startTrans();
            $dbFlag = true;
            //维护刊登映射表
            $products = ShopeeProduct::field('spu,account_id')->where(['id'=>['in', $ids]])->select();
            foreach ($products as $product) {
                GoodsPublishMapService::update(9, $product['spu'], $product['account_id'], 0);
            }
            ShopeeProduct::destroy($ids);
            ShopeeProductInfo::destroy($ids);
            ShopeeVariant::destroy(function($query) use ($ids){
                $query->where('pid', 'in', $ids);
            });
            Db::commit();
            return count($ids);
        } catch (\Exception $e) {
            if ($dbFlag) {
                Db::rollback();
            }
            return $e->getMessage();
        }
    }


/**********************************************************************************************************************/

    /**
     * 同步数据时，批量增删改查数据库
     * @param $data
     *              new_ids     新的目标ids，比如category,attribute
     *              new_items   新的数据,根据目标不同而不同
     *              old_ids     旧的目标ids
     *              item        要操作的目标键名，比如category_id,attribute_id
     * @param $modelClass       要操作的模型类
     * @param $where
     *              del_wh      删除时除了目标id之外的附加条件，比如站点，账号
     *              update_wh   更新查询时除了目标id之外的附加条件，比如站点，账号
     * @param bool $operationTime   是否写入创建时间和更新时间
     * @return bool
     */
    public function curdDb($data, $modelClass, $where, $operationTime=true)
    {
        $dbFlag = false;
        try {
            $newItemIds = $data['new_ids'];
            $newItems = $data['new_items'];
            $insertItemIds = array_diff($newItemIds, $data['old_ids']);//需要插入的
            $delItemIds = array_diff($data['old_ids'], $newItemIds);//需要删除的
            $updateItemIds = array_diff($newItemIds, $insertItemIds);//需要更新的
            unset($data['old_ids']);
            Db::startTrans();
            $dbFlag = true;
            //删除
            if (!empty($delItemIds)) {
                $map = $where['del_wh'];
                $map[$data['item']] = ['in', $delItemIds];
                $modelClass::destroy($map);
            }

            //新增
            if (!empty($insertItemIds)) {
                $insertItems = [];
                $tmpItems = $newItems;
                foreach ($tmpItems as $k => $tmpItem) {
                    if (in_array($tmpItem[$data['item']], $insertItemIds)) {
                        $operationTime && $tmpItem['create_time'] = time();
                        $insertItems[] = $tmpItem;
                        unset($newItems[$k]);//释放掉新增的，剩下的都是需要更新的
                        unset($newItemIds[$k]);//同时释放掉id,与上面的保持索引一致
                    }
                }
                (new $modelClass())->saveAll($insertItems, false);
            }

            //更新
            if (!empty($updateItemIds)) {
                //获取旧信息
                $wh = $where['update_wh'];
                $updateField = 'id,'.$data['item'];
                $wh[$data['item']] = ['in',$updateItemIds];
                $needUpdateItems = $modelClass::field($updateField)->where($wh)->select();

                $newItemIds = array_flip($newItemIds);
                //将主键id组装到更新信息中，以便批量更新
                foreach ($needUpdateItems as $needUpdateItem) {
                    $index = $newItemIds[$needUpdateItem[$data['item']]];
                    $newItems[$index]['id'] = $needUpdateItem['id'];
                    $operationTime && $newItems[$index]['update_time'] = time();
                }
                $updateItems = array_values($newItems);

                (new $modelClass())->saveAll($updateItems);
            }
            Db::commit();
        } catch (\Exception $e) {
            if ($dbFlag) {
                Db::rollback();
            }
            return $e->getMessage();
        }
        return true;
    }

    /**
     * 检查返回的结果是否正确
     * @param $response
     * @param $key
     * @return bool|string
     */
    public function checkResponse($response)
    {
        if (is_numeric($response['code']) && intval($response['code']) == 0) {
            return true;
        }
        return 'error_code:'. $response['code']. '--error_msg:'. $response['message'];
    }

    /**
     * 获取对应站点或账号的认证信息
     * @param $country
     * @return array|string
     * @throws \think\db\exception\DataNotFoundException
     */
    public function getAuthorization($accountId, $siteCode='')
    {
        $wh = [
            'platform_status' => 1,
            'app_key' => ['neq', ''],
            'status' => 1,
        ];
        if ($accountId) {
            $wh['id'] = $accountId;
        } else {
            $wh['site'] = $siteCode;
        }
        $field = 'site, app_key, app_secret, access_token';
        $account = LazadaAccount::field($field)->where($wh)->find();
        if ($account) {
            $config = $account->toArray();
            return $config;
        } else {
            return false;
        }
    }

    /**
     * 获取产品信息
     * @param $productId
     * @param int $itemId
     * @return array|string
     */
    public function getProduct($productId, $itemId=0)
    {
        try {
            if ($productId) {
                $wh = ['id'=>$productId];
            } else {
                $wh = ['item_id'=>$itemId];
            }
            $product = ShopeeProduct::get($wh);
            if (empty($product)) {
                throw new \Exception('获取产品信息失败');
            }
            $productId = $product['id'];
            $productInfo = ShopeeProductInfo::get($productId);
            $variant = ShopeeVariant::where(['pid'=>$productId])->select();
            $data = [
                'product' => $product->toArray(),
                'productInfo' => $productInfo->toArray(),
                'variant' => json_decode(json_encode($variant), true)
            ];
            return $data;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 格式化刊登的数据
     * @param $productData
     * @return string
     */
    public function formatItemData($productData)
    {
        try {
            $product = $productData['product'];
            $productInfo = $productData['productInfo'];
            $variant = $productData['variant'];
            $item['category_id'] = (int)$product['category_id'];
            $item['name'] = $product['name'];
            $item['description'] = $productInfo['description'];
            $item['name'] = $product['name'];
            $item['item_sku'] = $product['item_sku'];

            $variations = [];//变体
            foreach ($variant as $k => $var) {
                $variations[$k]['name'] = $var['name'];
                $variations[$k]['stock'] = (int)$var['stock'];
                $variations[$k]['price'] = (float)$var['price'];
                $variations[$k]['variation_sku'] = $var['variation_sku'];
            }
            $item['variations'] = $variations;
            //图片
            $code = ShopeeAccount::where(['id'=>$product['account_id']])->value('code');
            $images = json_decode($product['images'], true);
            foreach ($images as $k => $image) {
                $item['images'][$k]['url'] = GoodsImage::getThumbPath($image, 0, 0, $code, true);
            }
            //属性
            $attributes = json_decode($productInfo['attributes'], true);
            if (!empty($attributes)) {
                $itemAttributes = [];
                foreach ($attributes as $k => $attribute) {
                    if (!isset($attribute['attribute_value'])) {
                        continue;
                    }
                    $itemAttributes[] = [
                        'attributes_id' => (int)$attribute['attribute_id'],
                        'value' => $attribute['attribute_value']
                    ];
                }
                $item['attributes'] = $itemAttributes;
            }
            //物流
            $logistics = json_decode($productInfo['logistics'], true);
            $formatLogistics = [];
            foreach ($logistics as $k => $logistic) {
                $formatLogistics[$k]['logistic_id'] = (int)$logistic['logistic_id'];
                $formatLogistics[$k]['enabled'] = $logistic['enabled'] == 1 ? 0 : 1;
                if (isset($logistic['fee_type'])) {
                    if ($logistic['fee_type'] == 'CUSTOM_PRICE') {
                        $formatLogistics[$k]['shipping_fee'] = (float)$logistic['shipping_fee'];
                    } else if ($logistic['fee_type'] == 'SIZE_SELECTION') {
                        $formatLogistics[$k]['size_id'] = (int)$logistic['size_id'];
                    }
                }
                $formatLogistics[$k]['is_free'] = $logistic['is_free'] == 1 ? true : 0;
            }
            $item['logistics'] = $formatLogistics;
            $item['weight'] = (float)$product['weight'];
            !empty($product['package_length']) && $item['package_length'] = (int)$product['package_length'];
            !empty($product['package_width']) && $item['package_width'] = (int)$product['package_width'];
            !empty($product['package_height']) && $item['package_height'] = (int)$product['package_height'];
            if ($product['days_to_ship']>=7 && $product['days_to_ship']<=30) {
                $item['days_to_ship'] = $product['days_to_ship'];
            }
            //批发
            $wholesales = json_decode($productInfo['wholesales'], true);
            if (!empty($wholesales)) {
                $itemWholesales = [];
                foreach ($wholesales as $k => $wholesale) {
                    $itemWholesales[$k]['min'] = (int)$wholesale['min'];
                    $itemWholesales[$k]['max'] = (int)$wholesale['max'];
                    $itemWholesales[$k]['unit_price'] = (float)$wholesale['unit_price'];
                }
                $item['wholesales'] = $itemWholesales;
            }
            return $item;
        } catch (\Exception $e) {
            return $e->getMessage();
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
            $wh['status'] = 1;
            $salesmenIds = ShopeeProduct::distinct(true)->where($wh)->column('create_id');
            return $salesmenIds;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 根据商品id获取刊登过该商品的销售员
     * @param $skuId
     * @return array
     */
    public static function getSalesmenByGoodsId($goodsId)
    {
        try {
            $wh['goods_id'] = $goodsId;
            $wh['status'] = 1;
            $salesmenIds = ShopeeProduct::distinct(true)->where($wh)->column('create_id');
            return $salesmenIds;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 删除LAZADA平台上的产品
     * @param $accountId
     * @param $skuListJson
     * @return bool
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     */
    public function removeProductsByAccountId($accountId,$skuListJson)
    {
        //获取当前用户的accountId
//        $user_info = CommonService::getUserInfo();
//        $seller_id = '';
        $config = $this->getAuthorization($accountId);
        if (!$config) {
            throw new Exception('账号信息不存在或已禁用');
        }
        $config['site'] = strtolower($config['site']);
        $config['service_url'] = LazadaUtil::getSiteLink($config['site']);//根据站点获取请求的url
        $response = LazadaApi::instance($config)->handler('product')->removeProduct($skuListJson);//删除线上产品
        $message = $this->checkResponse($response);
        if (is_string($message)) {
            throw new Exception($message);
        }
        return true;
    }





    /**
     * @title 同步品牌到数据库(暂时不做)
     * @param $accountId
     * @param $offset
     * @param $pageSize
     * @return bool
     * @throws DbException
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
//    public function syncBrands($accountId, $offset, $pageSize)
//    {
//        set_time_limit(0);
//        # 1.获取平台数据
//        $config = $this->getAuthorization($accountId);
//        if (!$config) {
//            throw new Exception('账号信息不存在或已禁用');
//        }
//        $config['site'] = strtolower($config['site']);
//        $siteId = LazadaSite::where('code',$config['site'])->find()['id'];
//        //获取请求的url
//        $serviceUrl = LazadaUtil::getSiteLink($config['site']);
//        $config['service_url'] = $serviceUrl;
//        $params['page'] = $offset;
//        $params['page_size'] = $pageSize;
//        $response = LazadaApi::instance($config)->handler('brands')->getBrands($params);//获取线上brands
//        $message = $this->checkResponse($response);
//        if (is_string($message)) {
//            throw new Exception($message);
//        }
//        $re = $response['data'];
////        dump($re);die();
//        if (empty($re)){
//            return 2;//数据请求完了
//        }
//        # 2.组合数据并更新数据库
//        foreach ($re as $k=>$v){
//            unset($re[$k]['global_identifier']);
//            $re[$k]['site_id'] = $siteId;
//            $re[$k]['account_id'] = $accountId;
//        }
//        $data['new_ids'] = array_column($re,'brand_id');
//        $data['item'] = 'brand_id';
//        $data['old_ids'] = LazadaBrand::where('account_id', $accountId)->column('brand_id');
//        $data['new_items'] = $re;
//
//        $where = [
//            'del_wh' => ['site_id'=>$siteId],
//            'update_wh' => ['site_id'=>$siteId]
//        ];
//        try{
//            $res = $this->curdDb($data, LazadaBrand::class, $where);
//        }catch (\Exception $e) {
//            return 0;
//        }
//        if($res){
//            return 1;
//        }else{
//            return 0;
//        }
//
//    }


}