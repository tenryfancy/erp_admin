<?php

namespace app\report\service;

use app\common\cache\Cache;
use app\common\model\FbaOrder;
use app\common\model\Order;
use app\common\model\OrderDetail;
use app\common\model\OrderPackage;
use app\common\model\report\ReportStatisticByDeeps;
use app\common\service\Report;
use app\common\service\UniqueQueuer;
use app\index\service\DepartmentUserMapService;
use app\order\service\OrderService;
use app\report\queue\WriteBackFbaOrderDeeps;
use app\report\queue\WriteBackOrderDeeps;
use app\warehouse\service\Warehouse;
use think\Db;
use think\Exception;

/** 销售额统计
 * Created by PhpStorm.
 * User: phill
 * Date: 2017/8/1
 * Time: 19:16
 */
class StatisticDeeps
{
    protected $reportStatisticByDeepsModel = null;

    public function __construct()
    {
        if (is_null($this->reportStatisticByDeepsModel)) {
            $this->reportStatisticByDeepsModel = new ReportStatisticByDeeps();
        }
    }

    /**
     * 列表数据
     * @param $data
     * @return false|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function lists($data)
    {
        $where = [];
        $this->where($data, $where);
        $lists = $this->reportStatisticByDeepsModel->field(true)->where($where)->select();
        return $lists;
    }

    /** 搜索条件
     * @param $data
     * @param $where
     * @return \think\response\Json
     */
    private function where($data, &$where)
    {
        if (isset($data['channel_id']) && !empty($data['channel_id'])) {
            $where['channel_id'] = ['eq', $data['channel_id']];
        }
        if (isset($data['site_code']) && !empty($data['site_code'])) {
            $where['site_code'] = ['eq', $data['site_code']];
        }
        if (isset($data['warehouse_id']) && !empty($data['warehouse_id'])) {
            $where['warehouse_id'] = ['eq', $data['warehouse_id']];
        }
        if (isset($data['warehouse_type']) && !empty($data['warehouse_type'])) {
            $where['warehouse_type'] = ['eq', $data['warehouse_type']];
        }
        if (isset($data['account_id']) && !empty($data['account_id'])) {
            $where['account_id'] = ['eq', $data['account_id']];
        }
        if (isset($data['user_id']) && !empty($data['user_id'])) {
            $where['user_id'] = ['eq', $data['user_id']];
        }
        $data['date_b'] = isset($data['date_b']) ? $data['date_b'] : 0;
        $data['date_e'] = isset($data['date_e']) ? $data['date_e'] : 0;
        $condition = timeCondition($data['date_b'], $data['date_e']);
        if (!is_array($condition)) {
            return json(['message' => '日期格式错误'], 400);
        }
        if (!empty($condition)) {
            $where['dateline'] = $condition;
        }
    }

    /**
     * 回写月度销售额目标数据
     * @param int $begin_time
     * @param int $end_time
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function writeBackMonthAccount($begin_time = 0, $end_time = 0)
    {
        try {
            $reportStatisticByDeepsModel = new ReportStatisticByDeeps();
            if (!empty($begin_time) && !empty($end_time)) {
                $where['dateline'] = ['between', [$begin_time, $end_time]];
            } else if (!empty($begin_time)) {
                $where['dateline'] = ['eq', $begin_time];
            }
            $dataAmount = [];
            //统计调整
            $dataList = $reportStatisticByDeepsModel->field('user_id,sum(sale_amount / rate) as total_sale_amount,sum(delivery_quantity) as total_delivery_quantity')->where($where)->group('user_id')->select();
            foreach ($dataList as $k => $value) {
                $value = $value->toArray();
                $dataAmount[$value['user_id']] = $value;
            }
            //具体每个分类仓库的操作
            $dataWarehouseList = $reportStatisticByDeepsModel->field('user_id,warehouse_id,sum(sale_amount / rate) as total_sale_amount,sum(delivery_quantity) as total_delivery_quantity')->where($where)->group('warehouse_id,user_id')->select();
            $warehouseService = new Warehouse();
            foreach ($dataWarehouseList as $k => $value) {
                $value = $value->toArray();
                if (isset($dataAmount[$value['user_id']]) && !empty($value['warehouse_id'])) {
                    if (!isset($dataAmount[$value['user_id']]['distribution'])) {
                        $dataAmount[$value['user_id']]['distribution'] = [
                            'local_warehouse_amount' => 0, //本地仓金额
                            'oversea_warehouse_amount' => 0,//海外仓金额
                            'fba_warehouse_amount' => 0, //fba金额
                            'fba_warehouse_orders' => 0,//fba订单数
                        ];
                    }
                    $warehouse_type = $warehouseService->getTypeById($value['warehouse_id']);
                    switch ($warehouse_type) {
                        case 1:   //本地仓库
                            $dataAmount[$value['user_id']]['distribution']['local_warehouse_amount'] += $value['total_sale_amount'];
                            break;
                        case 3:
                            $dataAmount[$value['user_id']]['distribution']['oversea_warehouse_amount'] += $value['total_sale_amount'];
                            break;
                        case 4:
                            $dataAmount[$value['user_id']]['distribution']['fba_warehouse_amount'] += $value['total_sale_amount'];
                            $dataAmount[$value['user_id']]['distribution']['fba_warehouse_orders'] += $value['total_delivery_quantity'];
                            break;
                    }
                }
            }
            //回写记录
            foreach ($dataAmount as $k => $info) {
                $monthlyTargetAmountService = new MonthlyTargetAmountService();
                $monthlyTargetAmountService->addAmount($k, $info['total_sale_amount'], $info['total_delivery_quantity'], $info['distribution']);
            }
        } catch (Exception $e) {
            throw $e;
        }
    }


    /**
     * 统计会写
     * @param $begin_time
     * @param $end_time
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function writeBackPackage($begin_time, $end_time)
    {
        $where['shipping_time'] = ['between', [$begin_time, $end_time]];
//        $packageList = (new OrderPackage())->field('id')->where($where)->select();
//        foreach ($packageList as $k => $packageInfo){
//            //$this->updateReportByDelivery($packageInfo);
//            (new UniqueQueuer(WriteBackOrderDeeps::class))->push($packageInfo['id']);
//        }
        Db::table('order_package')->field('id')->where($where)->chunk(10000, function ($packageList) {
            foreach ($packageList as $packageInfo) {
                (new UniqueQueuer(WriteBackOrderDeeps::class))->push($packageInfo['id']);
            }
        });
    }

    /**
     * 发货之后更新统计信息
     * @param $packageInfo
     */
    public function updateReportByDelivery($package_id)
    {
        try {
            //包裹信息
            $packageInfo = (new OrderPackage())->field(true)->where(['id' => $package_id])->find();
            $time = strtotime(date('Y-m-d', $packageInfo['shipping_time']));
            //订单信息
            $orderInfo = (new Order())->field(true)->where(['id' => $packageInfo['order_id']])->find();
            //详情
            $orderDetailModel = new OrderDetail();
            $packageInfoCost = $orderDetailModel->field('sum(sku_cost * sku_quantity) as cost')->where(['package_id' => $packageInfo['id']])->find();
//            $detailCost = $orderDetailModel->field('sum(sku_cost * sku_quantity) as cost')->where(['order_id' => $orderInfo['id']])->find();
            $totalCost = $orderInfo['cost'] ?? 0;
            $packageCost = $packageInfoCost['cost'] ?? 0;
            if($totalCost > 0){  //通过 成本比例算钱
                $totalAmount = $packageCost / $totalCost * $orderInfo['pay_fee'] * $orderInfo['rate'];
            }else{
                $totalAmount = 0;
            }
            $payPal_fee = 0;
            $channel_cost = 0;
            if (!empty(floatval($orderInfo['pay_fee']))) {
                $payPal_fee = ($totalAmount / ($orderInfo['pay_fee'] * $orderInfo['rate'])) * ($orderInfo['paypal_fee'] * $orderInfo['rate']);
                $channel_cost = ($totalAmount / ($orderInfo['pay_fee'] * $orderInfo['rate'])) * $orderInfo['channel_cost'] * $orderInfo['rate'];
            }
            if($channel_cost > $totalAmount){
                Cache::handler()->hSet('hash:statistic:deeps:exception:' . date('Y-m-d', time()), date('Ymd H:i:s'),
                    json_encode(['order_number' => $orderInfo['order_number'],'number' => $packageInfo['number'],'p_cost' => $packageCost,'t_cost' => $totalCost, 'total' => $totalAmount,'channel' => $channel_cost],JSON_UNESCAPED_UNICODE));
            }
            $p_fee = 0;
            if ($packageInfo['channel_id'] == \app\common\service\ChannelAccountConst::channel_amazon) {
                $p_fee = sprintf("%.4f", ($totalAmount - $channel_cost) * 0.006);
            }
            $profits = $totalAmount - $packageInfo['shipping_fee'] - $packageInfo['package_fee'] - $orderInfo['first_fee'] - $orderInfo['tariff'] - $payPal_fee - $channel_cost;
            //平台账号统计销售业绩
            Report::saleByDeeps($packageInfo['channel_id'], $orderInfo['site_code'], $packageInfo['warehouse_id'],
                $orderInfo['channel_account_id'], [
                    'payPal' => $payPal_fee,  //paypal费用
                    'channel' => $channel_cost,  //平台手续费
                    'sale' => $totalAmount,  //销售额
                    'shipping_fee' => $packageInfo['shipping_fee'], //运费
                    'package' => $packageInfo['package_fee'],  //包装费
                    'first' => $orderInfo['first_fee'],  //头程费
                    'tariff' => $orderInfo['tariff'],  //头程报关税
                    'profits' => $profits,  //利润
                    'delivery' =>  (new OrderService())->countOrderQuantity($orderInfo),  //渠道账号的订单发货总数
                    'cost' => $orderInfo['delivery_type'] == 1 ? $orderInfo['cost'] : 0,//订单成本
                    'p_fee' => $p_fee //p_fee
                ], $time);
        } catch (Exception $e) {
            Cache::handler()->hSet('hash:statistic:deeps:error:' . date('Y-m-d', time()), date('Ymd H:i:s'), json_encode(['package_id' => $package_id, 'message' => $e->getMessage()],JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 发货fba之后更新统计信息
     * @param $packageInfo
     */
    public function updateReportByFba($order_id)
    {
        try {
            $orderInfo = (new FbaOrder())->field(true)->where(['id' => $order_id])->find();
            $time = strtotime(date('Y-m-d', $orderInfo['create_time']));
            Report::saleByDeeps($orderInfo['channel_id'], $orderInfo['site'], $orderInfo['warehouse_id'], $orderInfo['channel_account_id'], [
                'sale' => $orderInfo['pay_fee'] * $orderInfo['rate'],
                'delivery' => 1
            ], $time);
        } catch (Exception $e) {
            Cache::handler()->hSet('hash:statistic:fba:error:' . date('Y-m-d', time()), date('Ymd H:i:s'), json_encode(['order_id' => $order_id, 'message' => $e->getMessage()],JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 回写fba数据
     * @param $begin_time
     * @param $end_time
     */
    public function writeBackFbaOrder($begin_time, $end_time)
    {
        try {
            //订单信息
            $where['create_time'] = ['between', [$begin_time, $end_time]];
            Db::table('fba_order')->field('id')->where($where)->chunk(10000, function ($orderList) {
                foreach ($orderList as $orderInfo) {
                    (new UniqueQueuer(WriteBackFbaOrderDeeps::class))->push($orderInfo['id']);
                }
            });
        } catch (Exception $e) {

        }
    }

    /**
     * 统计会写[直接统计写表] 一天的数据
     * @param $begin_time
     * @param $end_time
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function writeBackPackageTable($begin_time, $end_time)
    {
        try {
            //包裹信息
            $where['shipping_time'] = ['between', [$begin_time, $end_time]];
            $field = 'id,order_id,channel_id,warehouse_id,shipping_fee,package_fee,shipping_id,number,shipping_time';
            Db::table('order_package')->field($field)->where($where)->chunk(100, function ($packageList) {
                $this->saleByPackages($packageList);
            });
        } catch (Exception $e) {

        }
    }

    /**
     *  统计会写[直接统计写表] 原始数据
     * @param $packageList
     * @return bool
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function saleByPackages($packageList)
    {
        $orderIds = array_column($packageList,'order_id');
        $packageIds = array_column($packageList,'id');
        $warehouseIds = array_column($packageList,'id');

        //仓库类型
        $warehouseIds = array_unique($warehouseIds);
        $where = [
            'id' => ['in', $warehouseIds]
        ];
        $warehouseTypes = Db::table('warehouse')->where($where)->column('type','id');

        //订单信息
        $where = [
            'id' => ['in', $orderIds]
        ];
        $field = 'id,cost,create_time,pay_fee,rate,country_code,buyer_id,channel_cost,first_fee,tariff,channel_account_id,delivery_type,belong_type,paypal_fee';
        $list = Db::table('order')->field($field)->where($where)->select();
        $orderList = $this->changeArrayKey($list);

        //详情信息
        $where = [
            'package_id' => ['in', $packageIds]
        ];
        $field = 'package_id,sku_cost,sku_quantity,sku_price,goods_id,sku_id';
        $list = Db::table('order_detail')->field($field)->where($where)->select();
        $orderDetails = [];
        foreach ($list as $v){
            if(isset($orderDetails[$v['package_id']])){
                $orderDetails[$v['package_id']][] = $v;
            }else{
                $orderDetails[$v['package_id']] = [$v];
            }
        }
        //查询产品信息
        $goodsIds = array_column($list,'goods_id');
        $where = [
            'id' => ['in', $goodsIds]
        ];
        $field = 'id,category_id,developer_id,purchaser_id,sales_status,publish_time';
        $list = Db::table('goods')->where($where)->field($field)->select();
        $goodsList = $this->changeArrayKey($list);

        return $this->saleByData($packageList, $warehouseTypes, $orderList, $orderDetails, $goodsList);
    }

    /**
     * 统计会写[直接统计写表] 合并数据
     * @param $packageList
     * @param $warehouseTypes
     * @param $orderList
     * @param $orderDetails
     * @param $goodsList
     * @return bool
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function saleByData($packageList, $warehouseTypes, $orderList, $orderDetails, $goodsList)
    {
        //分类统计
        $saleByCategory = [];
        //国家统计
        $saleByCountry = [];
        //日期统计
        $saleByDate = [];
        //产品统计
        $saleByGoods = [];
        //包裹发货数统计
        $saleByPackage = [];
        //平台账号统计销售业绩
        $saleByDeeps = [];

        $currencyData = Cache::store('currency')->getCurrency('USD');
        $system_rate = $currencyData['system_rate'];   //转换为人民币了

        foreach ($packageList as $package){
            $orderInfo = $orderList[$package['order_id']] ?? false;
            if(!$orderInfo){
                continue;
            }
            $dateline = strtotime(date('Y-m-d', $package['shipping_time']));
            $packageTotalAmount = 0;
            $totalCost = $orderInfo['cost'] ?? 0;
            $warehouseType = $warehouseTypes[$package['warehouse_id']] ?? 0; //仓库类型
            //订单创建时间
            $createTimeDateline = strtotime(date('Y-m-d', $orderInfo['create_time']));
            foreach ($orderDetails[$package['id']] as $detail){
                $goodsInfo = $goodsList[$detail['goods_id']] ?? false;
                if(!$goodsInfo){
                    continue;
                }
                $categoryId = $goodsInfo['category_id'];//分类ID
                $totalAmount = 0;
                if ($totalCost > 0) {
                    $totalAmount = ($detail['sku_cost'] * $detail['sku_quantity']) / $totalCost * $orderInfo['pay_fee'] * $orderInfo['rate'];
                }
                $packageTotalAmount += $totalAmount;

                //分类统计
                $key = $createTimeDateline . '_' . $package['channel_id'] . '_' . $categoryId;
                if(isset($saleByCategory[$key])){
                    $saleByCategory[$key]['sale_quantity']  += $detail['sku_quantity'];
                    $saleByCategory[$key]['sale_amount']  += $totalAmount;
                }else{
                    $saleByCategory[$key] = [
                        'sale_quantity' => $detail['sku_quantity'],       //销售数
                        'sale_amount' => $totalAmount   //销售额
                    ];
                }

                //国家统计
                $key = $createTimeDateline . '_' . $orderInfo['country_code'] . '_' . $detail['sku_id'];
                if(isset($saleByCountry[$key])){
                    $saleByCountry[$key]['sale_quantity']  += $detail['sku_quantity'];
                }else{
                    $saleByCountry[$key] = [
                        'sale_quantity' => $detail['sku_quantity'],       //销售数
                        'goods_id' => $detail['goods_id'],
                    ];
                }

                //日期统计
                $year = date('Y' , $orderInfo['create_time']);
                $month = date('m' , $orderInfo['create_time']);
                $key = $year. '_'. $month . '_' . $detail['sku_id'];
                if(isset($saleByDate[$key])){
                    $saleByDate[$key]['sale_quantity']  += $detail['sku_quantity'];
                }else{
                    $saleByDate[$key] = [
                        'sale_quantity' => $detail['sku_quantity'],       //销售数
                        'goods_id' => $detail['goods_id'],
                        'category_id' => $categoryId,
                    ];
                }

                //产品统计
                $key = $createTimeDateline . '_' . $package['channel_id'] . '_' . $package['warehouse_id'] . '_' . $detail['sku_id'];
                if(isset($saleByGoods[$key])){
                    $saleByGoods[$key]['sale_quantity']  += $detail['sku_quantity'];
                }else{
                    $saleByGoods[$key] = [
                        'sale_quantity' => $detail['sku_quantity'],       //销售数
                        'goods_id' => $detail['goods_id'],
                        'warehouse_type' => $warehouseType,
                        'category_id' => $categoryId,
                        'developer_id' => $goodsInfo['developer_id'],
                        'purchaser_id' => $goodsInfo['purchaser_id'],
                        'new_listing' => 0,
                    ];
                    //查看是否为新品
                    if ($goodsInfo['sales_status'] == 1 && ($goodsInfo['publish_time'] - time()) < 30 * 24 * 60 * 60) {
                        $saleByGoods[$key]['new_listing'] = 1;   //是新品
                    }
                }

                //统计sku的买家数
                Report::saleByBuyer($package['channel_id'], $orderInfo['buyer_id'], $detail['sku_id'], $orderInfo['create_time'], [
                    'buyer' => 1  //买家数笔数
                ]);
            }
            unset($orderDetails[$package['id']]);

            //包裹发货数统计
            $key = $createTimeDateline . '_' . $package['channel_id'] . '_' . $package['warehouse_id'] . '_' . $package['shipping_id'] . '_' . $orderInfo['country_code'];
            if(isset($saleByPackage[$key])){
                $saleByPackage[$key]['package_quantity']  += 1;
            }else{
                $saleByPackage[$key] = [
                    'package_quantity' => 1,       //包裹数（发货的）
                    'warehouse_type' => $warehouseType,
                ];
            }

            $payPal_fee = 0;
            $channel_cost = 0;
            if (!empty(floatval($orderInfo['pay_fee']))) {
                $payPal_fee = ($packageTotalAmount / ($orderInfo['pay_fee'] * $orderInfo['rate'])) * ($orderInfo['paypal_fee'] * $orderInfo['rate']);
                $channel_cost = ($packageTotalAmount / ($orderInfo['pay_fee'] * $orderInfo['rate'])) * $orderInfo['channel_cost'] * $orderInfo['rate'];
            }
            $p_fee = 0;
            if ($package['channel_id'] == \app\common\service\ChannelAccountConst::channel_amazon) {
                $p_fee = sprintf("%.4f", ($packageTotalAmount - $channel_cost) * 0.006);
            }
            $profits = $packageTotalAmount - $package['shipping_fee'] - $package['package_fee'] - $orderInfo['first_fee'] - $orderInfo['tariff'] - $payPal_fee - $channel_cost;

            //平台账号统计销售业绩
            $key = $dateline . '_' . $package['channel_id'] . '_' . $orderInfo['channel_account_id'] . '_' . $package['warehouse_id'];
            if(isset($saleByDeeps[$key])){
                $saleByDeeps[$key]['paypal_fee'] += $payPal_fee;  //paypal费用
                $saleByDeeps[$key]['channel_cost'] += $channel_cost;  //平台手续费
                $saleByDeeps[$key]['sale_amount'] += $packageTotalAmount;  //销售额
                $saleByDeeps[$key]['shipping_fee'] += $package['shipping_fee']; //运费
                $saleByDeeps[$key]['package_fee'] += $package['package_fee'];  //包装费
                $saleByDeeps[$key]['first_fee'] += $orderInfo['first_fee'];  //头程费
                $saleByDeeps[$key]['tariff'] += $orderInfo['tariff'];  //头程报关税
                $saleByDeeps[$key]['profits'] += $profits;  //利润
                $saleByDeeps[$key]['delivery_quantity'] += (new OrderService())->countOrderQuantity($orderInfo);  //渠道账号的订单发货总数
                $saleByDeeps[$key]['cost'] += $orderInfo['delivery_type'] == 1 ? $orderInfo['cost'] : 0;//订单成本
                $saleByDeeps[$key]['p_fee'] += $p_fee;//p_fee
            }else{
                $saleByDeeps[$key] = [
                    'paypal_fee' => $payPal_fee,  //paypal费用
                    'channel_cost' => $channel_cost,  //平台手续费
                    'sale_amount' => $packageTotalAmount,  //销售额
                    'shipping_fee' => $package['shipping_fee'], //运费
                    'package_fee' => $package['package_fee'],  //包装费
                    'first_fee' => $orderInfo['first_fee'],  //头程费
                    'tariff' => $orderInfo['tariff'],  //头程报关税
                    'profits' => $profits,  //利润
                    'delivery_quantity' => (new OrderService())->countOrderQuantity($orderInfo),  //渠道账号的订单发货总数
                    'cost' => $orderInfo['delivery_type'] == 1 ? $orderInfo['cost'] : 0,//订单成本
                    'p_fee' => $p_fee, //p_fee
                    'warehouse_type' => $warehouseType,
                    'user_id' => 0,
                    'department_id' => 0,
                    'rate' => $system_rate,
                ];

                //查询user id 这里可能会很慢
                $orderService = new OrderService();
                $userData = $orderService->getSales($package['channel_id'], $orderInfo['channel_account_id'], $warehouseType);
                if (!empty($userData)) {
                    $saleByDeeps[$key]['user_id'] = $userData['seller_id'];
                    //查找部门id
                    $userInfo = Cache::store('user')->getOneUser($saleByDeeps[$key]['user_id']);
                    if (!empty($userInfo)) {
                        $departmentUserMapService = new DepartmentUserMapService();
                        $department_ids = $departmentUserMapService->getDepartmentByUserId($saleByDeeps[$key]['user_id']);
                        $saleByDeeps[$key]['department_id'] = !empty($department_ids) ? $department_ids[0] ?? 0 : 0;
                    }
                }
            }
        }

//        var_dump($saleByCategory,$saleByCountry,$saleByDate,$saleByDeeps,$saleByGoods);die;
        $this->saveSaleByData($saleByCategory,'report_statistic_by_category',
            ['dateline','channel_id','category_id'],
            ['sale_amount','sale_quantity']);

        $this->saveSaleByData($saleByCountry,'report_statistic_by_country',
            ['dateline','country_code','sku_id'],
            ['sale_quantity']);

        $this->saveSaleByData($saleByDate,'report_statistic_by_date',
            ['year','month','sku_id'],
            ['sale_quantity']);

        $this->saveSaleByData($saleByGoods,'report_statistic_by_goods',
            ['dateline','channel_id','warehouse_id','sku_id'],
            ['sale_quantity']);

        $this->saveSaleByData($saleByPackage,'report_statistic_by_package',
            ['dateline','channel_id','warehouse_id','shipping_id','country_code'],
            ['package_quantity']);

        $this->saveSaleByData($saleByDeeps,'report_statistic_by_deeps',
            ['dateline','channel_id','account_id','warehouse_id'],
            ['paypal_fee','channel_cost','sale_amount','shipping_fee','package_fee','first_fee','tariff','profits','delivery_quantity','cost','p_fee']);

        return true;

    }

    /**
     * 统计会写[直接统计写表] 保存数据
     * @param $saleByData
     * @param $table
     * @param $wheres
     * @param $saves
     * @return bool
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    private function saveSaleByData($saleByData, $table, $wheres, $saves)
    {
        $model = Db::name($table);
        foreach ($saleByData as $k => $v){
            $keys = explode('_', $k);
            $i = 0;
            $where = [];
            foreach ($wheres as $key){
                $where[$key] = $keys[$i++];
            }
            try{
                $old = $model->where($where)->find();
//                //异常捕捉
                if($old){
                    $save = [];
                    foreach ($saves as $key){
                        $save[$key] = $old[$key] + $v[$key];
                    }
                    $save = $this->checkData($save);
                    $model->where($where)->update($save);
                }else{
                    $save = array_merge($where, $v);
                    $save = $this->checkData($save);
                    $model->insert($save);
                }
            }catch (\Exception $e){
                $save = array_merge($where, $v);
                Cache::handler()->hSet('hash:save_sale_by_data:'.date('YmdH') .':' .$table ,date('YmdHis') . rand(1,1000), json_encode($save) .$e->getMessage().$e->getFile().$e->getLine());
            }

        }
        return true;
    }


    /**
     * 检查数据
     * @param array $data
     * @return array
     */
    private function checkData(array $data)
    {
        $newData = [];
        foreach ($data as $k => $v) {
            if (is_numeric($v)) {
                if ($v < 0) {
                    $newData[$k] = 0;
                } else {
                    $newData[$k] = $v;
                }
            } else {
                $newData[$k] = $v;
            }
        }
        return $newData;
    }


    /**
     * 更换数组key
     * @param $list
     * @param string $newKey
     * @return array
     */
    private function changeArrayKey(&$list, $newKey = 'id')
    {
        $reData = [];
        foreach ($list as $v){
            $reData[$v[$newKey]] = $v;
        }
        unset($list);
        return $reData;
    }
}