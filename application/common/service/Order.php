<?php

namespace app\common\service;

use app\common\cache\Cache;
use app\common\exception\ChannelBuyerException;
use app\common\exception\OrderAddressException;
use app\common\exception\OrderDetailException;
use app\common\exception\OrderException;
use app\common\exception\OrderPackageException;
use app\common\exception\OrderSourceException;
use app\common\model\ChannelBuyer;
use app\common\model\ChannelBuyerAddress;
use app\common\model\FbaOrder;
use app\common\model\FbaOrderDetail;
use app\common\model\FbaOrderSourceDetail;
use app\common\model\fbm\FbmOrder;
use app\common\model\fbm\FbmOrderDetail;
use app\common\model\fbm\FbmOrderSourceDetail;
use app\common\model\OrderDetail;
use app\common\model\GoodsSkuAlias;
use app\common\model\OrderNote;
use app\common\model\OrderSourceDetail;
use app\common\model\OrderAddress;
use app\common\model\OrderPackage;
use app\common\service\Common as CommonService;
use app\common\model\GoodsSkuMap;
use app\common\model\GoodsSku;
use app\common\service\Report as ReportService;
use app\common\traits\ConfigCommon;
use app\index\service\ConfigService;
use app\order\queue\OrderUpdateQueue;
use app\order\queue\WriteBackAliExpressOrder;
use app\order\queue\WriteBackAmazonOrder;
use app\order\queue\WriteBackCdOrder;
use app\order\queue\WriteBackDarazOrder;
use app\order\queue\WriteBackDistributionOrder;
use app\order\queue\WriteBackEbayOrder;
use app\order\queue\WriteBackFunmartOrder;
use app\order\queue\WriteBackJoomOrder;
use app\order\queue\WriteBackJumiaOrder;
use app\order\queue\WriteBackLazadaOrder;
use app\order\queue\WriteBackOberloOrder;
use app\order\queue\WriteBackPandaoOrder;
use app\order\queue\WriteBackPaytmOrder;
use app\order\queue\WriteBackPddOrder;
use app\order\queue\WriteBackUmkaOrder;
use app\order\queue\WriteBackVovaOrder;
use app\order\queue\WriteBackWalmartOrder;
use app\order\queue\WriteBackWishOrder;
use app\order\queue\WriteBackShopeeOrder;
use app\order\queue\WriteBackYandexOrder;
use app\order\queue\WriteBackZoodmallOrder;
use app\order\service\OrderHelp;
use app\order\service\OrderRuleCheckService;
use app\order\service\OrderService;
use app\common\model\Order as OrderModel;
use app\common\service\Twitter as ServiceTwitter;
use app\order\service\OrderStatisticService;
use app\order\service\PackageService;
use app\order\service\VirtualOrderHoldService;
use app\purchase\service\SupplierOfferService;
use app\warehouse\service\ShippingMethod;
use app\warehouse\service\StockOut;
use app\warehouse\service\Warehouse;
use app\common\model\Warehouse as WarehouseModel;
use app\warehouse\service\WarehouseGoods as WarehouseGoodsService;
use app\warehouse\service\Delivery;
use app\common\model\Packing;
use app\index\service\Currency;
use app\warehouse\service\WarehouseGoods;
use think\Db;
use think\Exception;
use app\order\service\DeclareService;
use app\common\model\OrderMatchSuccessRule;
use app\common\model\FbsOrder;
use app\common\model\FbsOrderDetail;
use app\common\model\FbsOrderSourceDetail;

/**
 * Created by PhpStorm.
 * User: PHILL
 * Date: 2016/12/19
 * Time: 11:24
 */
class Order
{
	use ConfigCommon;

	/**
	 * 新增订单
	 * @param array $orderList
	 * @throws Exception
	 */
	public function add(array $orderList)
	{
		$orderModel = new OrderModel();
		$sourceModel = new OrderSourceDetail();
		$addressModel = new OrderAddress();
		foreach ($orderList as $key => $value) {
			if (!isset($value['order']) || !isset($value['orderDetail'])) {
				continue;
			}
			$orderPackage = [];
			$orderNewPackage = [];
			$default_log = [
				'message' => '系统自动抓取订单',
				'operator' => '系统自动',
				'process_id' => 0
			];
			$log = [];
			$order = $value['order'];
			$detail = $value['orderDetail'];
			if ($order['channel_id'] == ChannelAccountConst::channel_Distribution) {
				$shippingCode = $value['shipping']['shipping_code'] ?? '';
				$shippingCode = explode('_', $shippingCode);
				$order['shipping_id'] = $shippingCode[0] ?? 0;
				$warehouseCode = $value['shipping']['warehouse_code'] ?? '';
				$warehouseCode = explode('_', $warehouseCode);
				$order['warehouse_id'] = $warehouseCode[0] ?? 0;
			}
			try {
				if (empty($order) || empty($detail)) {
					continue;
				}
				//对表进行操作
				if (!isset($order['order_number'])) {
					$this->recordResult($order, false, $order['channel_order_id'] . '--没有订单编号');
					$this->failure_log($order['channel_order_id'] . '--没有订单编号');
					continue;
				}
				//检查缓存订单是否已存在
				if (Cache::store('order')->getOrder(trim($order['channel_order_number']), $order['channel_id'],
					$order['channel_account_id'])
				) {
					$this->recordResult($order, true);
					continue;
				}
				//再做一次检查数据库
				if ($orderModel->checkOrder([
					'channel_order_number' => $order['channel_order_number'],
					'channel_id' => $order['channel_id']
				])
				) {
					$this->recordResult($order, true);
					continue;
				}
				$order['create_time'] = time();
				$order['order_number'] = trim($order['order_number']);
				//查找汇率
				$currencyData = Cache::store('currency')->getCurrency($order['currency_code']);
				if (!isset($currencyData['system_rate'])) {
					$this->recordResult($order, false, $order['currency_code'] . '找不到对应的汇率');
					$this->failure_log($order['channel_order_id'] . '---' . $order['channel_id'] . '---' . $order['currency_code'] . '找不到对应的汇率');
					continue;
				}
				if (!isset($order['buyer_id'])) {
					$order['buyer_id'] = $order['buyer'];
				}
				$order['rate'] = $currencyData['system_rate'];
				//先检查默认规则
				$default = OrderRuleCheckService::checkDefaultRule($order, $detail, $log);

				if (!$default['is_default']) {
					$order['status'] = $default['status'];
				}
				//订单地址表
				$address = $this->addAddress($order);
				//订单详情
				$detail_list = $this->getOrderDetail($detail, $order);

				$this->cost($order);   //渠道手续费
				//检查是否为刷单
				$this->buyerInfo($order, $address);
				(new VirtualOrderHoldService())->holdByVirtualOrder($order, $address);
				if ($default['is_default'] && $order['is_scalping'] == 0) {
					//执行添加商品的规则
					OrderRuleCheckService::checkRule($order, $detail_list, $log, 6);   //新增商品
				}
				//订单源信息
				$source = $this->getOrderSource($detail, $order);

				//检查是否 无需发货
				$isBT3 = $this->checkOrderBelongType($order);

				//看看是否先被截取了，默认规则
				if ($default['is_default'] && $order['is_scalping'] == 0) {
					//包裹与详情
					$package = $this->getPackage($detail_list, $order);
					//把包裹的信息推到订单信息里
					foreach ($package as $p => $pp) {
						$new_array = array_merge($order, $pp);
						array_push($orderPackage, $new_array);
					}
					//记录订单分包裹的一些日记信息
					$temp = [
						'message' => '订单生成' . count($package) . '个包裹',
						'operator' => '系统自动',
						'process_id' => 0
					];
					array_push($log, $temp);   //加入日志信息
					//检查规则
					foreach ($orderPackage as $o => $oo) {
						$temp = $oo;
						if ($order['channel_id'] == ChannelAccountConst::channel_Distribution || $isBT3) {
							$temp['status'] = OrderStatusConst::ForDistribution;
						} else {
							OrderRuleCheckService::checkRule($temp, $detail_list, $log);
						}
						$order = $temp;
						if (!empty($default['status'])) {
							$newStatus = $this->mergeOrderStatus($default['status'], $order['status']);
							$order['status'] = $newStatus;
						}
						array_push($orderNewPackage, $temp);
						//记录缓存
						Cache::store('order')->setPackageShipping($temp['channel_id'], $temp['channel_order_number'], $temp['shipping_id']);
					}
					Cache::store('order')->delPackageShipping($order['channel_id'], $order['channel_order_number']);
				}
				$detailNewList = [];  //新的订单详情信息
				//检查订单是否已存在
				if (isset($order['id']) && isset($order['order_id'])) {
					$order['channel_order'] = json_encode([$order['id'] => $order['order_id']]);
					unset($order['id']);
					unset($order['order_id']);
				}
				$order['update_time'] = $order['create_time'];
				$order['check_time'] = time();
				$order['type'] = 0;
				if (count($orderNewPackage) > 1) {
					foreach ($orderNewPackage as $p => $pp) {
						if ($pp['status'] < OrderStatusConst::ForDistribution && $pp['status'] != $order['status']) {
							$order['status'] = $pp['status'];
							break;
						}
					}
				} else {
					$order['status'] = isset($order['status']) ? $order['status'] : OrderStatusConst::ForDistribution;
				}
				$order['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
					$order['channel_account_id']);
				if ($order['id'] < 1) {
					$this->recordResult($order, false, $order['channel_order_id'] . '生产订单id错误');
					$this->failure_log($order['channel_order_id'] . '生产订单id错误');
					continue;
				}
				$is_save = false;
				if (isset($order['type'])) {
					$order['type'] = 0;   //渠道
				}
				//是否作废订单（刷单）
				if ($order['is_scalping'] == 1) {
					$order['status'] = OrderStatusConst::SaidInvalid;
					$order['reason_for_invalid'] = '刷单订单';
					$order['time_for_invalid'] = time();
					$order['type'] = 2;   //刷单
					$new_log = [
						'message' => '订单标记为作废,原因：刷单订单',
						'operator' => '系统自动',
						'process_id' => OrderStatusConst::SaidInvalid
					];
					array_push($log, $new_log);
				}
				$address['order_id'] = $order['id'];
				$address['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
					$order['channel_account_id']);
				$last_log = [];
				if ($order['pay_time'] == 0) {
					$order['status'] = 0;
					$order['check_time'] = 0;
					$last_log = [
						'message' => '订单检查出PayPal未付款',
						'operator' => '系统自动',
						'process_id' => 0
					];
				}
				if (empty($order['channel_id']) || empty($order['channel_account_id'])) {
					$this->recordResult($order, false, '--账号id信息为空3');
					$this->failure_log($order['channel_order_id'] . '--账号id信息为空3');
					continue;
				}
				if (empty($source)) {
					$this->recordResult($order, false, '--来源信息为空');
					$this->failure_log($order['channel_order_id'] . '--来源信息为空');
					continue;
				}
				//获取销售员
				$this->sales($order);
				Db::startTrans();
				try {
					//记录缓存
					Cache::store('order')->setOrder(trim($order['channel_order_number']), $order['channel_id'],
						$order['channel_account_id'], $order['order_time']);
					$orderModel->allowField(true)->isUpdate(false)->save($order);
					//是否新增备注
					$this->note($order);
					//插入地址信息
					$addressModel->allowField(true)->isUpdate(false)->save($address);
					//插入来源信息
					foreach ($source as $s => $ss) {
						$ss['order_id'] = $order['id'];
						$ss['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
							$order['channel_account_id']);
						$sourceModel->allowField(true)->isUpdate(false)->save($ss);
						$sourceList = $sourceModel->field('id,channel_sku')->where(['order_id' => $order['id']])->order('id desc')->find();
						if (empty($sourceList) || !isset($sourceList['id']) || empty($sourceList['id']) || empty($sourceList['channel_sku'])) {
							throw new OrderException('订单来源表错误');
							break;
						}
						//这一步有问题，出现重复的,加上item_id来限制
						foreach ($detail_list as $d => $dd) {
							if (isset($dd['channel_sku']) && $dd['channel_sku'] == $ss['channel_sku'] && $dd['channel_item_id'] == $ss['channel_item_id']) {
								if (isset($ss['transaction_id']) && !empty($ss['transaction_id'])) {
									if ($ss['transaction_id'] == $dd['transaction_id']) {
										$dd['order_source_detail_id'] = isset($sourceList['id']) ? $sourceList['id'] : 0;
										array_push($detailNewList, $dd);
									}
								} else {
									$dd['order_source_detail_id'] = isset($sourceList['id']) ? $sourceList['id'] : 0;
									array_push($detailNewList, $dd);
								}
							}
						}
					}
					//写日志
					CommonService::addOrderLog($order['id'], $default_log['message'], $default_log['operator'],
						$default_log['process_id']);
					Db::commit();
					//记录成功信息
					//Cache::store('order')->setSuccessOrder($order['order_number'], $order);
					$is_save = true;
				} catch (Exception $e) {
					Db::rollback();
					//删除缓存
					Cache::store('order')->delOrder(trim($order['channel_order_number']), $order['channel_id'],
						$order['channel_account_id']);
					throw new OrderException($e->getMessage() . $e->getFile() . $e->getLine());
				}
				//加入回写队列
				$this->recordResult($order, true);
				if ($order['pay_time'] > 0) {
					$this->countOrder($order, $order['pay_time'], ['order' => 1, 'pay' => $order['pay_fee'] * $order['rate']]);   //统计已付款的订单数
				} else {  //未付款
					$this->countOrder($order, $order['create_time'], ['unpaid' => 1]);   //统计未付款的订单数
				}
				$state = $default['is_default'] && $is_save && $order['is_scalping'] == 0 ? true : false;
				$this->baggageHandling($orderNewPackage, $detailNewList, $order, $log, $state);
				//记录最后的日志
				if (!empty($last_log)) {
					CommonService::addOrderLog($order['id'], $last_log['message'], $last_log['operator'],
						$last_log['process_id']);
				}
				if ($isBT3) {
					//直接发货
					$params = [
						'type' => 'updateOrderDelivery',
						'data' => [
							'order_id' => $order['id'] . '',
							'shipping_time' => time(),
						],
					];
					(new UniqueQueuer(OrderUpdateQueue::class))->push($params);
				}
			} catch (OrderException $e) {
				$this->failure_log(date('Y-m-d H:i:s') . '-' . $order['channel_order_id'] . '---' . $order['channel_id'] . $e->getMessage() . $e->getFile() . $e->getLine());
				$this->recordResult($order, false, $e->getMessage());
			} catch (OrderPackageException $e) {
				//写订单日志
				CommonService::addOrderLog($order['id'], '匹配的商品信息保存失败，需人工手动新增商品操作', '系统自动', 0);
				//把订单划入为 商品未知
				$orderModel->where(['id' => $order['id']])->update(['status' => OrderStatusConst::CommodityIsUnknown]);
				$this->log(date('Y-m-d H:i:s') . '-' . $order['channel_order_id'] . '---' . $order['channel_id'] . $e->getMessage() . $e->getFile() . $e->getLine());
			} catch (Exception $e) {
				$this->log(date('Y-m-d H:i:s') . '-' . $order['channel_order_id'] . '---' . $order['channel_id'] . $e->getMessage() . $e->getFile() . $e->getLine());
				$this->recordResult($order, false, $e->getMessage());
			}
		}
	}

	/**
	 * 检查是否 无需发货
	 * @param $order
	 * @return bool
	 * @throws Exception
	 *
	 */
	private function checkOrderBelongType(&$order)
	{
		if (isset($order['belong_type']) && $order['belong_type'] == 3) {
			$no_delivery_shipping_code = $this->getConfigData('order_no_delivery_shipping_code');
			if ($no_delivery_shipping_code) {
				$shippingCode = explode('#', $no_delivery_shipping_code);
				if (isset($shippingCode[1])) {
					$order['shipping_id'] = (new ShippingMethod())->getShippingIdByCode($shippingCode[0], $shippingCode[1]);
				}
			}
			//不必要的费用设置为0
			$order['first_fee'] = 0;   //头程费
			$order['tariff'] = 0;   //关税
			$order['cost'] = 0;  //成本价
			$order['warehouse_id'] = 7;  //指定仓库 2
			$order['distribution_time'] = time();  //自动配货
			$order['providers_shipping_time'] = time();  //物流商发货时间
			$order['package_upload_status'] = time();  //包裹上传
			$order['package_confirm_status'] = time();  //包裹是否交运给物流商
			$order['upload_to_warehouse'] = time();  //上传到仓库
			$order['packing_time'] = time();  //包装时间
			$order['print_time'] = time();  //打印时间
			$order['package_collection_id'] = 1;  //集包时间
			$order['package_collection_time'] = time();  //集包时间
			return true;
		}
		return false;
	}

	/**
	 * 记录包裹，详情信息
	 * @param $orderNewPackage [包裹数据]
	 * @param $detailNewList [详情数据]
	 * @param $order [订单数据]
	 * @param $log [日志数据]
	 * @param $state [标识]
	 * @throws OrderPackageException
	 */
	private function baggageHandling($orderNewPackage, $detailNewList, $order, &$log, $state)
	{
		$detailModel = new OrderDetail();
		$orderPackageModel = new OrderPackage();
		$declareService = new DeclareService();
		$orderModel = new OrderModel();
		$packageArray = [];
		try {
			//默认规则通过的情况
			if ($state) {
				$packageDeclareAll = [];
				$packageGoodsAll = [];
				$shippingFeeAmount = 0;   //总运费
				$is_merge = false;
				$order_ids = [$order['id']];
				$merge_record_log = [];
				//插入包裹和详情的信息
				foreach ($orderNewPackage as $p => $pp) {
					//记录这个包裹有哪些产品
					$packageGoods = [];
					//判断是否要合包裹
					if (count($orderNewPackage) > 1) {
						$accord = [];
					} else {
						if (!$this->IsCombination($order)) {
							$accord = [];
						} else {
							$accord = $this->combinationPackage($order, $pp);
						}
					}
					if (!empty($accord)) {
						$mergeStatus = $pp['status'];
						//合并包裹
						$detailNewList = $this->mergePackage($pp, $accord, $order, $detailNewList, $log, $mergeStatus, $order_ids);
						$is_merge = true;
						$merge_log = [
							'message' => '订单合并了包裹，重新执行物流方式规则,需人工审核规则',
							'operator' => '系统自动',
							'process_id' => 0
						];
						array_push($log, $merge_log);
						array_push($merge_record_log, $merge_log);
						$pp['status'] = $mergeStatus;
						$pp['is_wish_express'] = $order['is_wish_express'] ?? '';
						$pp['requires_delivery_confirmation'] = $order['requires_delivery_confirmation'] ?? '';
						//重新跑物流规则
						$new_log = [];
						$pp['is_merge'] = 1;  //是否是合并订单 1-是 0-否
						OrderRuleCheckService::checkRule($pp, $detailNewList, $new_log, [2, 3]);
						$log = array_merge($log, $new_log);
						$merge_record_log = array_merge($merge_record_log, $new_log);
						$newStatus = $this->mergeOrderStatus($mergeStatus, $pp['status']);   //状态可能变化了
						$pp['status'] = $newStatus;
						$shippingFeeAmount = $pp['estimated_fee'] ?? 0;
					} else {
						$pp['order_id'] = $order['id'];
						$pp['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
							$order['channel_account_id']);
						if (empty($pp['id']) || $pp['id'] < 0) {
							//重新生成一下
							$pp['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
								$order['channel_account_id']);
						}
						//计算估计运费
						$shippingFeeAmount += $pp['estimated_fee'] ?? 0;
						$pp['status'] = $order['status'];
					}
					//包裹整理
					if (empty($pp['id']) || $pp['id'] < 0) {
						//重新生成一下
						$pp['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
							$order['channel_account_id']);
					}
					foreach ($detailNewList as $k => $v) {
						$list = $v;
						//看看这个商品是属于哪个包裹的
						if (isset($pp['contains'])) {
							if (in_array($v['sku_id'], $pp['contains'])) {
								$list['package_id'] = $pp['id'];
								if (!isset($list['id'])) {
									$list['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
										$order['channel_account_id']);
									$list['order_id'] = $order['id'];
								}
								array_push($packageGoods, $v);
								array_push($packageGoodsAll, $list);
							}
						} else {
							$list['package_id'] = $pp['id'];
							if (!isset($list['id'])) {
								$list['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
									$order['channel_account_id']);
								$list['order_id'] = $order['id'];
								array_push($packageGoods, $v);
							}
							array_push($packageGoodsAll, $list);
						}
					}
					//删除合并之后多余的sku信息
					foreach ($packageGoodsAll as $key => $value) {
						if (empty($value['order_source_detail_id'])) {
							unset($packageGoodsAll[$key]);
						}
					}
					if (!isset($pp['order_id'])) {
						$pp['order_id'] = $order['id'];
					}
					//包裹申报
					$packageDeclare = $declareService->matchDeclare($pp, $packageGoods, $log);
					$packageDeclareAll[$pp['id']] = $packageDeclare;
					//记录包裹号
					$packageArray[$pp['id']] = $pp;
					//记录日志
					//Cache::store('order')->setPackageIdLog($order['order_number'], $pp);
				}
				$originalList = $orderModel->field('status,id')->where('id', 'in', $order_ids)->select();
				$originalStatus = [];
				foreach ($originalList as $key => $value) {
					$originalStatus[$value['id']] = $value['status'];
				}
				Db::startTrans();
				try {
					foreach ($packageArray as $package => $pp) {
						//包裹申报
						$declareService->storage($packageDeclareAll[$pp['id']]);
						//记录匹配的规则id
						$this->recordRule($pp);
						if ($is_merge) {
							$orderPackageModel->allowField(true)->isUpdate(true)->save($pp);
							//更改订单状态
							$orderModel->where('id', 'in', $order_ids)->update(['status' => $pp['status']]);
							//订单状态调整，防止下面的步骤统计信息了
							$order['status'] = $pp['status'];
							//合并的订单id
							foreach ($order_ids as $o => $oid) {
								if ($oid == $order['id']) {
									continue;
								}
								//统计信息
								$orderStatistic = new OrderStatisticService();
								$orderStatistic->adjustReport([], $oid, $originalStatus[$oid]);
								//记录日志
								CommonService::addOrderLog($oid, '订单合并了【' . $order['order_number'] . '】订单的包裹', '系统自动');
								//插入日志
								foreach ($merge_record_log as $g => $gg) {
									CommonService::addOrderLog($oid, $gg['message'], $gg['operator'], $gg['process_id'], 0, $pp['id']);
								}
							}
						}
					}
					$detailModel->allowField(true)->isUpdate(false)->saveAll($packageGoodsAll, false);
					if (!$is_merge) {
						$orderPackageModel->allowField(true)->isUpdate(false)->saveAll($packageArray, false);
					}
					if (!empty($shippingFeeAmount)) {
						$shippingFeeAmount = $shippingFeeAmount / count($order_ids);
						$orderModel->where('id', 'in', $order_ids)->update(['estimated_fee' => $shippingFeeAmount]);
					}
					if ($is_merge) {
						(new OrderHelp())->setBelongType($order_ids, OrderType::MergeOrder);
					}
					if ($order['status'] == OrderStatusConst::ForDistribution) {
						//记录统计信息
						foreach ($packageGoodsAll as $detail => $list) {
							$this->countReport($order, $list, $packageArray[$list['package_id']]);
						}
					}
					foreach ($packageArray as $package => $pp) {
						//记录包裹统计信息
						if (isset($pp['warehouse_id']) && isset($pp['shipping_id']) && $pp['status'] == OrderStatusConst::ForDistribution) {
							ReportService::saleByPackage($pp['channel_id'], $pp['warehouse_id'], $pp['shipping_id'],
								$order['country_code'], $order['create_time'], [
									'generated' => 1
								]);
						}
					}
					Db::commit();
				} catch (Exception $e) {
					Db::rollback();
					$this->save_log(date('Y-m-d H:i:s') . '-' . $order['channel_order_id'] . '---' . $order['channel_id'] . $e->getMessage() . $e->getFile() . $e->getLine() . "\r\n" . "包裹详情--" . json_encode($packageGoodsAll) . "\r\n" . "包裹信息--" . json_encode($packageArray) . "\r\n" . "包裹申报--" . json_encode($packageDeclareAll));
					CommonService::addOrderLog($order['id'], '包裹信息、商品详情信息保存失败', '系统', 0);
					throw new OrderPackageException($e->getMessage() . $e->getFile() . $e->getLine());
				}
			} else {
				$detailAll = [];
				foreach ($detailNewList as $k => $v) {
					$list = $v;
					$list['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
						$order['channel_account_id']);
					$list['order_id'] = $order['id'];
					array_push($detailAll, $list);
				}
				$detailModel->allowField(true)->isUpdate(false)->saveAll($detailAll, false);
			}
			//插入日志
			foreach ($log as $g => $gg) {
				CommonService::addOrderLog($order['id'], $gg['message'], $gg['operator'], $gg['process_id']);
			}
			$log = [];
			//自动配货
			if ($order['status'] == OrderStatusConst::ForDistribution) {
				$this->autoDistribution($order, $packageArray, $log, $detailModel);
			}
		} catch (OrderPackageException $e) {
			$this->package_log(date('Y-m-d H:i:s') . '-' . $order['channel_order_id'] . '---' . $order['channel_id'] . $e->getMessage() . $e->getFile() . $e->getLine());
			throw new OrderPackageException($e->getMessage() . $e->getFile() . $e->getLine());
		} catch (Exception $e) {
			$this->package_log(date('Y-m-d H:i:s') . '-' . $order['channel_order_id'] . '---' . $order['channel_id'] . $e->getMessage() . $e->getFile() . $e->getLine());
			throw new OrderPackageException($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	/**
	 * 是否要检查合并
	 * @param $order
	 * @return bool
	 */
	private function IsCombination($order)
	{
		if (in_array($order['channel_id'],
			[
				ChannelAccountConst::channel_ebay, ChannelAccountConst::channel_Pandao, ChannelAccountConst::channel_Paytm,
				ChannelAccountConst::channel_Lazada, ChannelAccountConst::channel_Yandex,ChannelAccountConst::channel_Joom,
				ChannelAccountConst::channel_Daraz,ChannelAccountConst::channel_Fummart,ChannelAccountConst::channel_Vova
			])) {
			return false;
		}
		if ($order['channel_id'] == ChannelAccountConst::channel_wish && !empty($order['is_epc_order'])) {
			return false;
		}
		if (isset($order['is_virtual_send']) && $order['is_virtual_send'] > 0) {
			return false;
		}
        if (isset($order['message']) && $order['message'] != '') {
            return false;
        }
		return true;
	}

	/**
	 * 合并包裹处理
	 * @param $pp
	 * @param $accord
	 * @param $order
	 * @param $detailNewList
	 * @param $log
	 * @param $mergeStatus
	 * @param $order_ids
	 * @return array
	 * @throws Exception
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\ModelNotFoundException
	 * @throws \think\exception\DbException
	 */
	private function mergePackage(&$pp, $accord, $order, $detailNewList, &$log, &$mergeStatus, &$order_ids)
	{
		$orderDetailModel = new OrderDetail();
		$orderModel = new OrderModel();
		$orderPackageModel = new OrderPackage();
		$packageGoods = [];
		//合并包裹,查询出要合并包裹的详情信息,这里不管原来的订单有多少个包裹，只管详情信息有多少
		$weight = 0;
		$accord_order_number = 0;  //订单编号
		$order_amount = $pp['order_amount'];   //订单金额
		$totalPayFee = $order['pay_fee'];
		$totalChannelCost = $order['channel_cost'];   //总渠道费
		$totalTariff = $order['tariff'] ?? 0;         //税费
		$totalFirstFee = $order['first_fee'] ?? 0;    //头程费
		$totalCost = $order['cost'] ?? 0;             //成本
		foreach ($accord as $a => $order_id) {
			array_push($order_ids, $order_id);
			//查询订单
			$orderInfo = $orderModel->field('channel_account', true)->where(['id' => $order_id])->find();
			$order_amount += isset($orderInfo['order_amount']) ? $orderInfo['order_amount'] : 0;
			$accord_order_number = isset($orderInfo['order_number']) ? $orderInfo['order_number'] : '';
			$totalPayFee += isset($orderInfo['pay_fee']) ? $orderInfo['pay_fee'] : 0;
			$totalChannelCost += isset($orderInfo['channel_cost']) ? $orderInfo['channel_cost'] : 0;
			$totalTariff += isset($orderInfo['tariff']) ? $orderInfo['tariff'] : 0;
			$totalFirstFee += isset($orderInfo['first_fee']) ? $orderInfo['first_fee'] : 0;
			$totalCost += isset($orderInfo['cost']) ? $orderInfo['cost'] : 0;
			$orderDetailList = $orderDetailModel->field(true)->where(['order_id' => $order_id])->select();
			foreach ($orderDetailList as $detail => $list) {
				$new['detail']['goods_id'] = $list['goods_id'];
				$new['detail']['sku_id'] = $list['sku_id'];
				$new['detail']['sku'] = $list['sku'];
				$new['detail']['sku_quantity'] = $list['sku_quantity'];
				//查出商品的具体资料
				$skuData = Cache::store('goods')->getSkuInfo($list['sku_id']);
				if (!empty($skuData)) {
					$weight = $weight + ($skuData['weight'] * $list['sku_quantity']);
				}
				$new['detail']['sku_title'] = $list['sku_title'];
				$new['detail']['sku_thumb'] = $list['sku_thumb'];
				$new['detail']['sku_price'] = $list['sku_price'];
				$new['detail']['channel_item_id'] = $list['channel_item_id'];
				$new['detail']['weight'] = $skuData['weight'];
				$new['detail']['cost_price'] = $this->getGoodsCostPriceBySkuId($list['sku_id'], $pp['warehouse_id']);
				//查找产品，获取头程费
				$goodsInfo = $this->getGoods($skuData['goods_id']);
				//产品的一些重量，体积信息
				$this->goodsDetailInfo($new, 'detail', $goodsInfo);
				array_push($packageGoods, $new['detail']);
				array_push($pp['contains'], $new['detail']['sku_id']);
			}
			if ($mergeStatus == OrderStatusConst::ForDistribution) {
				$mergeStatus = $orderInfo['status'];
			} else if ($orderInfo['status'] != OrderStatusConst::ForDistribution) {
				$mergeStatus = $mergeStatus | $orderInfo['status'];
			}
			if ($mergeStatus != OrderStatusConst::ForDistribution) {
				$mergeStatus = $mergeStatus | OrderStatusConst::MoreThanOneBuyerOrders;
			}
		}
		$contains = $pp['contains'];  //包裹包含的商品sku_id
		$rule_id = $pp['rule_id'] ?? 0;   //匹配的规则信息
		//求出第一个包裹信息
		$pp = $orderPackageModel->field('channel_account', true)->where('order_id', 'in', $accord)->find()->toArray();
		$pp['contains'] = $contains;
		$pp['order_amount'] = $order_amount;
		$pp['channel_cost'] = $totalChannelCost;   //总渠道费
		$pp['tariff'] = $totalTariff;              //总税费
		$pp['first_fee'] = $totalFirstFee;         //总头程费
		$pp['cost'] = $totalCost;                  //总成本
		$pp['buyer_id'] = $order['buyer_id'];
		$pp['estimated_weight'] += $order['estimated_weight'];
		$pp['rule_id'] = $rule_id;
		$pp['country_code'] = $order['country_code'];
		$pp['pay_fee'] = $totalPayFee;
		//拆好数据
		$detail_list = array_merge($packageGoods, $detailNewList);
		$pp['type'] = $this->checkType($detail_list, $order, $pp);
		$package = $this->bagging([$pp], $detail_list, $order);
		if (!empty($package)) {
			$pp['height'] = $package[0]['height'];
			$pp['width'] = $package[0]['width'];
			$pp['length'] = $package[0]['length'];
		}
		//记录日志
		//Cache::store('order')->setPackageMergeLog($order['order_number'], $pp);
		$merge_log = [
			'message' => '订单包裹与订单[' . $accord_order_number . ']合并了包裹，包裹号为[' . $pp['number'] . ']',
			'operator' => '系统自动',
			'process_id' => 0
		];
		array_push($log, $merge_log);
		return $detail_list;
	}

	/**
	 * 订单状态并存处理
	 * @param $defaultStatus [源状态]
	 * @param $status [重新跑规则之后的状态]
	 * @return number
	 */
	private function mergeOrderStatus($defaultStatus, $status)
	{
		if ($status == OrderStatusConst::ForDistribution) {
			$originStatus = $defaultStatus;
		} else if ($defaultStatus == OrderStatusConst::ForDistribution) {
			$originStatus = $status;
		} else {
			$originStatus = $defaultStatus | $status;
		}
		return $originStatus;
	}

	/**
	 * 记录平台推送结果
	 * @param $order
	 * @param $status
	 * @param $message 错误信息
	 */
	private function recordResult($order, $status, $message = '')
	{
		$data['order_id'] = $order['channel_order_id'];
		$data['order_time'] = $order['order_time'];
		$data['status'] = $status;
		switch ($order['channel_id']) {
			case ChannelAccountConst::channel_ebay:
				(new UniqueQueuer(WriteBackEbayOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_amazon:
				(new UniqueQueuer(WriteBackAmazonOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_aliExpress:
				(new UniqueQueuer(WriteBackAliExpressOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_wish:
				(new UniqueQueuer(WriteBackWishOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_Shopee:
				(new UniqueQueuer(WriteBackShopeeOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_Joom:
				(new UniqueQueuer(WriteBackJoomOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_Paytm:
				(new UniqueQueuer(WriteBackPaytmOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_Pandao:
				(new UniqueQueuer(WriteBackPandaoOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_Walmart:
				(new UniqueQueuer(WriteBackWalmartOrder::class))->push($data);
				break;
			case ChannelAccountConst::Channel_Jumia:
				(new UniqueQueuer(WriteBackJumiaOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_Lazada:
				(new UniqueQueuer(WriteBackLazadaOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_CD:
				(new UniqueQueuer(WriteBackCdOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_Distribution:
				(new UniqueQueuer(WriteBackDistributionOrder::class))->push(['channel_order_number' => $order['channel_order_number'], 'status' => $status, 'message' => $message]);
				break;
			case ChannelAccountConst::channel_Zoodmall:
				(new UniqueQueuer(WriteBackZoodmallOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_Yandex:
				(new UniqueQueuer(WriteBackYandexOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_Vova:
				(new UniqueQueuer(WriteBackVovaOrder::class))->push($data);
				break;
			case ChannelAccountConst::Channel_umka:
				(new UniqueQueuer(WriteBackUmkaOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_Pdd:
				(new UniqueQueuer(WriteBackPddOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_Daraz:
				(new UniqueQueuer(WriteBackDarazOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_Oberlo:
				(new UniqueQueuer(WriteBackOberloOrder::class))->push($data);
				break;
			case ChannelAccountConst::channel_Fummart:
				(new UniqueQueuer(WriteBackFunmartOrder::class))->push($data);
				break;
		}
	}

	/** 保存买家信息（查看是否为刷单买家）
	 * @param $order
	 * @param $address
	 * @throws ChannelBuyerException
	 */
	private function buyerInfo(&$order, $address)
	{
		$channelBuyerModel = new ChannelBuyer();
		$channelBuyerAddressModel = new ChannelBuyerAddress();
		$order['is_scalping'] = 0;
		try {
			//判断买家是否已经存在
			if ($result = $channelBuyerModel->isHas($order['channel_id'], $order['buyer_id'])) {
				if ($result['is_scalping'] == 1) {  //刷单买家
					$order['is_scalping'] = 1;
				}
			} else {
				//买家信息
				$buyer['channel_id'] = $order['channel_id'];
				$buyer['account_id'] = $order['channel_account_id'];
				$buyer['tel'] = $order['tel'];
				$buyer['mobile'] = $order['mobile'];
				$buyer['email'] = $order['email'];
				$buyer['name'] = $order['buyer'];
				$buyer['buyer_id'] = $order['buyer_id'];
				$buyer['create_time'] = $order['create_time'];
				$buyer['update_time'] = $order['create_time'];
				$channelBuyerModel->allowField(true)->isUpdate(false)->save($buyer);
				$channel_buyer_id = $channelBuyerModel->id;
				$buyerAddress = $address;
				$buyerAddress['channel_buyer_id'] = $channel_buyer_id;
				$channelBuyerAddressModel->allowField(true)->isUpdate(false)->save($buyerAddress);
			}
		} catch (ChannelBuyerException $e) {
			throw new ChannelBuyerException($e->getMessage() . $e->getFile() . $e->getLine());
		} catch (Exception $e) {
			throw new ChannelBuyerException($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	/**
	 * 检查分区
	 * @param $model
	 * @return bool
	 * @throws Exception
	 */
	public function checkPartition($model)
	{
		$time = time();
		if (!Cache::store('partition')->getPartition($model, $time)) {
			Cache::store('partition')->setPartition($model, $time, null, [], true);
		}
		return true;
	}

	/** 新增备注信息
	 * @param $order
	 */
	public function note($order)
	{
		if (isset($order['note']) && !empty($order['note'])) {
			$orderNoteModel = new OrderNote();
			$note['order_id'] = $order['id'];
			$note['note'] = $order['note'];
			$note['type'] = 1;   //规则
			$note['create_time'] = time();
			$orderNoteModel->allowField(true)->isUpdate(false)->save($note);
		}
	}

	/** 记录错误日志
	 * @param $message
	 */
	private function log($message)
	{
		$fileName = date('Y-m-d', time());
		$logFile = LOG_PATH . "order/" . $fileName . "_log.log";
		file_put_contents($logFile, '-----' . $message . "\r\n", FILE_APPEND);
	}

	/** 记录包裹错误日志
	 * @param $message
	 */
	private function package_log($message)
	{
		$fileName = date('Y-m-d', time());
		$logFile = LOG_PATH . "order/" . $fileName . "_package.log";
		file_put_contents($logFile, '-----' . $message . "\r\n", FILE_APPEND);
	}

	/** 记录包裹错误日志
	 * @param $message
	 */
	private function save_log($message)
	{
		$fileName = date('Y-m-d', time());
		$logFile = LOG_PATH . "order/" . $fileName . "_save.log";
		file_put_contents($logFile, '-----' . $message . "\r\n", FILE_APPEND);
	}

	/** 记录失败日志
	 * @param $message
	 */
	private function failure_log($message)
	{
		$logDir = LOG_PATH . 'order';
		if (!is_dir($logDir)) {
			//创建日志目录
			mkdir($logDir, 0777, true);
			chmod($logDir, 0777);
		}
		$fileName = date('Y-m-d', time());
		$logFile = $logDir . '/' . $fileName . "_failure.log";
		file_put_contents($logFile, '-----' . $message . "\r\n", FILE_APPEND);
	}

	/**
	 * 查找销售员
	 * @param $order
	 * @throws Exception
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\ModelNotFoundException
	 * @throws \think\exception\DbException
	 */
	public function sales(&$order)
	{
		$orderService = new OrderService();
		//查找销售员
		$warehouse_type = 0;
		if (isset($order['warehouse_id'])) {
			$warehouse_type = (new Warehouse())->getTypeById($order['warehouse_id']);
		}
		$seller_data = $orderService->getSales($order['channel_id'], $order['channel_account_id'], $warehouse_type);
		$order['seller'] = !empty($seller_data) ? $seller_data['seller'] : "";
		$order['seller_id'] = !empty($seller_data) ? $seller_data['seller_id'] : "";
	}

	/**
	 * 记录匹配成功的规则id
	 * @param $data
	 * @throws \Exception
	 */
	public function recordRule($data)
	{
		$successRuleModel = new OrderMatchSuccessRule();
		if (isset($data['rule_id']) && is_array($data['rule_id'])) {
			$rule = [];
			foreach ($data['rule_id'] as $k => $v) {
				$rule[$k]['order_id'] = $data['order_id'];
				$rule[$k]['package_id'] = $data['id'];
				$rule[$k]['rule_id'] = $v;
				$rule[$k]['create_time'] = time();
			}
			$successRuleModel->allowField(true)->isUpdate(false)->saveAll($rule);
		}
	}

	/**
	 * 计算渠道手续费
	 * @param $order
	 * @return mixed
	 * @throws Exception
	 */
	private function cost(&$order)
	{
		if (!isset($order['channel_cost'])) {
			$channel_name = Cache::store('channel')->getChannelName($order['channel_id']);
			$key = $channel_name . '_channel_cost';
			$config = Cache::store('configParams')->getConfig($key);
			$order['channel_cost'] = $order['pay_fee'] * $config['value'];
		}
	}

	/**
	 * 统计报表的信息
	 * @param $order
	 * @param $v
	 * @param $pp
	 * @return bool
	 * @throws Exception
	 */
	public function countReport($order, $v, $pp, $is_system = true)
	{
		//Cache::handler(true)->multi();
		try {
			if (isset($order['is_scalping']) && $order['is_scalping'] == 1) {
				return false;
			}
			//记录产品统计信息
			ReportService::saleByGoods($order['channel_id'], $v['goods_id'], $v['sku_id'],
				$pp['warehouse_id'], $order['create_time'], [
					'order' => $v['sku_quantity'],
					'buyer' => 1,   //买家数
					'turnover' => 1  //订单笔数
				], $is_system);
			$category_id = $this->getCategory($v['goods_id']);
			//分类统计
			ReportService::saleByCategory($category_id, $order['channel_id'], $order['create_time'], [
				'order' => $v['sku_quantity'],
				'turnover' => 1  //订单笔数
			]);
			//国家统计
			ReportService::saleByCountry($order['country_code'], $v['goods_id'], $v['sku_id'], $order['create_time'], [
				'turnover' => 1  //订单笔数
			]);
			//记录日期统计信息
			ReportService::saleByDate($v['goods_id'], $v['sku_id'], $category_id, $order['create_time'], [
				'turnover' => 1  //订单笔数
			]);
			// Cache::handler(true)->exec();
		} catch (Exception $e) {
			//Cache::handler(true)->discard();
			throw new Exception($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	/**
	 * 记录订单统计信息
	 * @param $order
	 * @param $time
	 * @param $type
	 * @return bool
	 */
	public function countOrder($order, $time, $type)
	{
		if (isset($order['is_scalping']) && $order['is_scalping'] == 1) {
			return false;
		}
		//记录订单统计信息
		ReportService::statisticOrder($order['channel_id'], $order['channel_account_id'], $time, $type);
	}

	/**
	 * 自动配货处理
	 * @param array $order
	 * @param array $packageArray
	 * @param array $log
	 * @param $detailModel
	 * @return array
	 * @throws Exception
	 */
	private function autoDistribution(array $order, array $packageArray, &$log, $detailModel)
	{
		try {
			if (isset($order['is_scalping']) && $order['is_scalping'] == 1) {
				return false;
			}
			$configService = new ConfigService();
			$configAuto = Cache::store('configParams')->getConfig('order_auto_distribution');  //查看默认的规则开启配置参数
			$configAuto = $configService->conversion($configAuto);
			if (empty($configAuto)) {
				$configAuto['value'] = 0;
			}
			$autoSkuIds = [];
			//判断是否开启了自动配货
			if (isset($order['auto']) || $configAuto['value'] == 1) {   //1-为启用
				$warehouseGoodsService = new WarehouseGoodsService();
				foreach ($packageArray as $k => $v) {
					$is_ok = true;
					//查出商品，判断库存是否合理
					$detailGoodsList = $detailModel->where(['package_id' => $v['id']])->select();
					if (empty($detailGoodsList)) {
						foreach ($detailGoodsList as $key => $value) {
							//查询库存
							$inventory = $warehouseGoodsService->getWarehouseGoods($value['warehouse_id'],
								$value['sku_id'], ['alert_quantity', 'available_quantity']);
							//需求数大于库存可用数 或者 库存数小于警戒数 自动跳出，不分配
							if ($value['sku_quantity'] > $inventory['available_quantity'] || $inventory['available_quantity'] < $inventory['alert_quantity']) {
								$is_ok = false;
								array_push($autoSkuIds, $value['sku_id']);
							}
						}
					}
					if ($is_ok) {
						$warehouse_name = Cache::store('warehouse')->getWarehouseNameById($v['warehouse_id']);
						$deliveryService = new Delivery(['warehouse_id' => $v['warehouse_id'], 'ids' => $v['id']]);
						$deliveryService->distribution(false);
						$temp = [
							'message' => '系统自动分配发货仓库：' . $warehouse_name,
							'operator' => '系统自动',
							'process_id' => 0
						];
						array_push($log, $temp);
					}
				}
			}
			if (isset($order['is_virtual_send']) && $order['is_virtual_send'] > 0) {
				(new OrderHelp())->autoGenerateProposals($autoSkuIds);
			}
		} catch (Exception $e) {
			throw new Exception($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	/**
	 * 物流商自动下单
	 */
	private function autoPlaceOrder()
	{
		//PlaceOrder::upload(0, '系统', 1);
	}

	/** 获取分类id
	 * @param $goods_id
	 * @return array|false|\PDOStatement|string|\think\Model
	 */
	private function getCategory($goods_id)
	{
		$goodsInfo = $this->getGoods($goods_id);
		if (!empty($goodsInfo)) {
			return $goodsInfo['category_id'];
		}
		return 0;
	}

	/** 创建包裹-关联详情
	 * @param $detail_list
	 * @param $order
	 * @return array
	 * @throws Exception
	 */
	public function getPackage($detail_list, &$order, $is_manual = false)
	{
		try {
			$package = [];
			$configService = new ConfigService();
			$configPackage = Cache::store('configParams')->getConfig('order_package_rule');  //查看默认的规则开启配置参数
			$configPackage = $configService->conversion($configPackage);
			if (empty($configPackage)) {
				$configPackage['value'] = 2;   //默认设置为2
			}
			if ($is_manual) {
				$configPackage['value'] = 0; //手工单，仓库已经选好
			}
			$weight = 0;
			$encryption = new Encryption();
			//判断配置
			switch ($configPackage['value']) {
				case 0:    //不启用拆单功能
					//就一个包裹
					//获取所有商品的重量
					foreach ($detail_list as $k => $v) {
						if (isset($v['sku_id'])) {
							//查出商品的具体资料
							$sku_list = Cache::store('goods')->getSkuInfo($v['sku_id']);
							if (!empty($sku_list)) {
								$weight = $weight + ($sku_list['weight'] * $v['sku_quantity']);
							}
						}
					}
					$temp = $order;
					$temp['estimated_weight'] = $weight;
					$temp['package_weight'] = 0;
					$temp['create_time'] = time();
					$temp['update_time'] = time();
					$temp['warehouse_id'] = isset($order['warehouse_id']) ? $order['warehouse_id'] : 0;
					$temp['shipping_id'] = isset($order['shipping_id']) ? $order['shipping_id'] : 0;
					$temp['channel_id'] = isset($order['channel_id']) ? $order['channel_id'] : 0;
					$temp['channel_account_id'] = isset($order['channel_account_id']) ? $order['channel_account_id'] : 0;
					$temp['status'] = isset($order['status']) ? $order['status'] : OrderStatusConst::ForDistribution;
					$temp['pay_time'] = isset($order['pay_time']) ? $order['pay_time'] : 0;
					$contains = [];
					foreach ($detail_list as $k => $v) {
						if (isset($v['goods_id'])) {
							array_push($contains, $v['sku_id']);
						}
					}
					$temp['contains'] = $contains;
					$temp['type'] = $this->checkType($detail_list, $order, $temp);
					$temp['number'] = $encryption->createNumber(time());
					if (empty($temp['number'])) {
						$temp['number'] = $encryption->createNumber(time());
					}
					array_push($package, $temp);
					$package = $this->bagging($package, $detail_list, $order);
					break;
				case 1:   //拦截
					$temp = $order;
					$temp['status'] = OrderStatusConst::YouReviewThe;
					$temp['type'] = $this->checkType($detail_list, $order);
					$temp['number'] = $encryption->createNumber(time());
					if (empty($temp['number'])) {
						$temp['number'] = $encryption->createNumber(time());
					}
					array_push($package, $temp);
					break;
				case 2:   //根据商品默认仓库自动拆分
					$new_package = [];
					foreach ($detail_list as $k => $v) {
						if (isset($v['goods_id'])) {
							//查出商品的具体资料
							$goodsInfo = $this->getGoods($v['goods_id']);
							$skuInfo = Cache::store('goods')->getSkuInfo($v['sku_id']);
							if ($goodsInfo['is_multi_warehouse'] == 0) {  //独立仓库
								if (isset($new_package[$goodsInfo['warehouse_id']])) {   //如果存在这个独立仓库
									$weight = ($skuInfo['weight'] * $v['sku_quantity']) + $new_package[$goodsInfo['warehouse_id']]['estimated_weight'];
									$new_package[$goodsInfo['warehouse_id']]['estimated_weight'] = $weight;
									array_push($new_package[$goodsInfo['warehouse_id']]['contains'], $v['sku_id']);
								} else {
									$weight = $skuInfo['weight'] * $v['sku_quantity'];
									$temp = $order;
									$temp['estimated_weight'] = $weight;
									$temp['package_weight'] = 0;
									$temp['create_time'] = time();
									$temp['update_time'] = time();
									$temp['warehouse_id'] = $goodsInfo['warehouse_id'];
									$temp['shipping_id'] = isset($order['shipping_id']) ? $order['shipping_id'] : 0;
									$temp['channel_id'] = isset($order['channel_id']) ? $order['channel_id'] : 0;
									$temp['channel_account_id'] = isset($order['channel_account_id']) ? $order['channel_account_id'] : 0;
									$temp['status'] = isset($order['status']) ? $order['status'] : OrderStatusConst::ForDistribution;
									$temp['pay_time'] = isset($order['pay_time']) ? $order['pay_time'] : 0;
									$temp['contains'] = [$v['sku_id']];
									$new_package[$goodsInfo['warehouse_id']] = $temp;
									$order['warehouse_id'] = $goodsInfo['warehouse_id'];
								}
							} else {
								if (isset($order['warehouse_id']) && isset($new_package[$order['warehouse_id']])) {    //不是独立仓库
									$weight = ($skuInfo['weight'] * $v['sku_quantity']) + $new_package[$order['warehouse_id']]['estimated_weight'];
									$new_package[$order['warehouse_id']]['estimated_weight'] = $weight;
									array_push($new_package[$order['warehouse_id']]['contains'], $v['sku_id']);
								} else {
									$weight = $skuInfo['weight'] * $v['sku_quantity'];
									$temp = $order;
									$temp['estimated_weight'] = $weight;
									$temp['package_weight'] = 0;
									$temp['create_time'] = time();
									$temp['update_time'] = time();
									$temp['warehouse_id'] = isset($order['warehouse_id']) ? $order['warehouse_id'] : $goodsInfo['warehouse_id'];
									$temp['shipping_id'] = isset($order['shipping_id']) ? $order['shipping_id'] : 0;
									$temp['channel_id'] = isset($order['channel_id']) ? $order['channel_id'] : 0;
									$temp['channel_account_id'] = isset($order['channel_account_id']) ? $order['channel_account_id'] : 0;
									$temp['status'] = isset($order['status']) ? $order['status'] : OrderStatusConst::ForDistribution;
									$temp['pay_time'] = isset($order['pay_time']) ? $order['pay_time'] : 0;
									$temp['contains'] = [$v['sku_id']];
									$new_package[$goodsInfo['warehouse_id']] = $temp;
									$order['warehouse_id'] = $goodsInfo['warehouse_id'];
								}
							}
						}
					}
					//拆好数据
					foreach ($new_package as $p => $pp) {
						$pp['type'] = $this->checkType($detail_list, $order, $pp);
						$pp['number'] = $encryption->createNumber(time());
						if (empty($pp['number'])) {
							$pp['number'] = $encryption->createNumber(time());
						}
						array_push($package, $pp);
					}
					$package = $this->bagging($package, $detail_list, $order);
					break;
			}
			return $package;
		} catch (Exception $e) {
			throw new OrderPackageException($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	/** 判断包裹是单品单件、单品多件、多品多件、备注
	 * @param $detail_list
	 * @param $order
	 * @param null $package
	 * @return int
	 * @throws Exception
	 */
	public function checkType(array $detail_list, $order, $package = null)
	{
		try {
			$skuType = [];   //sku 种类数
			$is_remark = false;
			$detailSkuList = [];
			foreach ($detail_list as $k => $v) {
				$skuType[$v['sku_id']] = 1;
				if (isset($detailSkuList[$v['sku_id']])) {
					foreach ($detailSkuList[$v['sku_id']] as $d => &$dd) {
						if ($dd['sku_id'] == $v['sku_id']) {
							$dd['sku_quantity'] += $v['sku_quantity'];
							break;
						}
					}
				} else {
					$detailSkuList[$v['sku_id']] = [$v];
				}
				if (isset($v['note']) && !empty($v['note'])) {
					$is_remark = true;
					break;
				}
			}
			if ($is_remark) {
				return PackageType::IncludeRemarks;
			}
			$detailSkuList = array_values($detailSkuList);
			$detailNewList = [];
			foreach ($detailSkuList as $key => $value) {
				if (is_array($value)) {
					foreach ($value as $k => $v) {
						array_push($detailNewList, $v);
					}
				}
			}
			$type = 0;
			if (is_null($package)) {
				//检查包裹类型（单品单件，单品多件，多品多件）
				if (count($skuType) == 1) {
					foreach ($detailNewList as $k => $v) {
						if ($v['sku_quantity'] > 1) {
							$type = PackageType::SingleMultiplePiece;  //单品多件
							break;
						} else {
							$type = PackageType::SinglePiece;  //单品单件
						}
					}
				} else {
					$type = PackageType::MultiMultiplePiece;   //多品多件
				}
			} else {
				if (isset($package['contains'])) {
					$skuType = [];
					foreach ($package['contains'] as $k => $v) {
						$skuType[$v] = 1;
					}
					if (count($skuType) == 1) {
						if (count($package['contains']) > 1) {
							$type = PackageType::SingleMultiplePiece;    //单品多件
						} else {
							foreach ($detailNewList as $k => $v) {
								if (isset($package['contains'][0]) && $v['sku_id'] == $package['contains'][0]) {
									if ($v['sku_quantity'] > 1) {
										$type = PackageType::SingleMultiplePiece;    //单品多件
										break;
									} else {
										$type = PackageType::SinglePiece;    //单品单件
									}
								}
							}
						}
					} else {
						$type = PackageType::MultiMultiplePiece; //多品多件
					}
				}
			}
			if (!empty($order['message'])) {
				//$type = PackageType::IncludeRemarks;  //备注
			}
			return $type;
		} catch (Exception $e) {
			throw new OrderPackageException($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	/** 获取订单地址信息数组
	 * @param $order
	 * @return array
	 * @throws Exception
	 */
	private function addAddress($order)
	{
		try {
			//订单地址表
			$address = [];
			$address['consignee'] = $order['consignee'];
			$address['country_code'] = $order['country_code'];
			$address['city'] = $order['city'];
			$address['province'] = $order['province'];
			$address['address'] = $order['address'];
			$address['address2'] = isset($order['address2']) ? $order['address2'] : '';
			$address['area_info'] = isset($order['district']) ? $order['district'] : '';
			$address['zipcode'] = $order['zipcode'];
			$address['tel'] = $order['tel'];
			$address['mobile'] = $order['mobile'];
			$address['email'] = $order['email'];
			$address['create_time'] = $order['create_time'];
			$address['update_time'] = $order['create_time'];
			$address['buyer_id'] = $order['buyer_id'];
			$address['channel_id'] = $order['channel_id'];
			$address['channel_account_id'] = $order['channel_account_id'];
			$sourceAddress = [
				'consignee' => $address['consignee'],
				'country_code' => $address['country_code'],
				'city' => $address['city'],
				'province' => $address['province'],
				'address' => $address['address'],
				'address2' => $address['address2'],
				'zipcode' => $address['zipcode'],
				'tel' => $address['tel'],
				'mobile' => $address['mobile'],
				'email' => $address['email'],
			];
			$address['source_address'] = json_encode($sourceAddress);
			$address['paypal_address'] = isset($order['paypal_address']) ? $order['paypal_address'] : '';
			return $address;
		} catch (Exception $e) {
			throw new OrderAddressException($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	/** paypal地址
	 * @param $payPal
	 * @return array
	 * @throws OrderAddressException
	 */
	public function payPalAddress($payPal)
	{
		try {
			$payPalAddress = [];
			if (!empty($payPal)) {
				$payPalAddress = [];
				$payPalAddress['consignee'] = $payPal['address_name'];
				$payPalAddress['country_code'] = $payPal['address_country'];
				$payPalAddress['city'] = $payPal['address_city'];
				$payPalAddress['province'] = $payPal['address_state'];
				$payPalAddress['address'] = $payPal['address_street'];
				$payPalAddress['address2'] = isset($payPal['address_street2']) ? $payPal['address_street2'] : '';
				$payPalAddress['zipcode'] = $payPal['address_zip'];
				$payPalAddress['tel'] = '';
				$payPalAddress['mobile'] = $payPal['phone'];
				$payPalAddress['email'] = '';
				$payPalAddress = json_encode($payPalAddress);
			}
			return $payPalAddress;
		} catch (Exception $e) {
			throw new OrderAddressException($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	/** 获取订单来源信息数组
	 * @param $detail
	 * @param $order
	 * @return array
	 * @throws Exception
	 */
	private function getOrderSource($detail, $order)
	{
		try {
			$source = [];
			foreach ($detail as $k => $v) {
				$source[$k]['channel_sku'] = $v['channel_sku'];
				$source[$k]['transaction_id'] = isset($v['transaction_id']) ? $v['transaction_id'] : '';
				$source[$k]['channel_item_id'] = $v['channel_item_id'];
				$source[$k]['channel_item_link'] = $v['channel_item_link'];
				$source[$k]['channel_sku_quantity'] = $v['sku_quantity'];
				$source[$k]['channel_sku_title'] = $v['channel_sku_title'];
				$source[$k]['channel_sku_price'] = $v['channel_sku_price'];
				$source[$k]['channel_currency_code'] = isset($v['channel_currency_code']) ? $v['channel_currency_code'] : '';
				$source[$k]['channel_sku_shipping_free_discount'] = isset($v['channel_sku_shipping_free_discount']) ? $v['channel_sku_shipping_free_discount'] : '';
				$source[$k]['channel_sku_shipping_free'] = isset($v['channel_sku_shipping_free']) ? $v['channel_sku_shipping_free'] : '';
				$source[$k]['channel_sku_discount'] = isset($v['channel_sku_discount']) ? $v['channel_sku_discount'] : '';
				$source[$k]['buyer_selected_logistics'] = $v['buyer_selected_logistics'] ?? '';
				$source[$k]['create_time'] = $order['create_time'];
				$source[$k]['update_time'] = $order['create_time'];
				$source[$k]['size'] = $v['size'] ?? '';
				$source[$k]['color'] = $v['color'] ?? '';
				$source[$k]['record_number'] = $v['record_number'] ?? '';
			}
			return $source;
		} catch (Exception $e) {
			throw new OrderSourceException($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	/** 获取订单匹配到的货品详情数组
	 * @param $detail
	 * @param $order
	 * @return array
	 * @throws Exception
	 */
	private function getOrderDetail($detail, &$order)
	{
		try {
			$first_fee = 0;
			$cost = 0;
			$tariff = 0;
			$goodsSkuMapModel = new GoodsSkuMap();
			$goodsSkuModel = new GoodsSku();
			$goodsSkuAliasModel = new GoodsSkuAlias();
			$detail_list = [];
			$kk = 0;

			// 是否 无需发货SKU
			$no_delivery_sku = $this->getConfigData('goods_no_delivery_sku');

			foreach ($detail as $k => $v) {
				$detail_list[$kk]['order_source_detail_id'] = $sourceList['id'] ?? 0;
				$detail_list[$kk]['transaction_id'] = $v['transaction_id'] ?? '';
				if (isset($v['sku_quantity'])) {
					$detail_list[$kk]['sku_quantity'] = $v['sku_quantity'];
				} else {
					if (isset($v['channel_sku_quantity'])) {
						$detail_list[$kk]['sku_quantity'] = $v['channel_sku_quantity'];
					}
				}
				$channelSkuQuantity = $detail_list[$kk]['sku_quantity'];
				$detail_list[$kk]['create_time'] = $order['create_time'];
				$detail_list[$kk]['update_time'] = $order['create_time'];
				//补上
				$detail_list[$kk]['buyer_selected_logistics'] = $v['buyer_selected_logistics'] ?? '';
				if ($order['channel_id'] == ChannelAccountConst::channel_Joom) {  //推送过来的是店铺，但是绑定的是账号
					$channelAccountId = Cache::store('JoomShop')->getAccountById($order['channel_account_id']);
					//匹配本地产品
					$sku_map = $goodsSkuMapModel->field('sku_code_quantity,is_virtual_send')->where([
						'channel_id' => $order['channel_id'],
						'account_id' => $channelAccountId['joom_account_id'] ?? $order['channel_account_id'],
						'channel_sku' => trim($v['channel_sku'])
					])->find();
				} else {
					//匹配本地产品
					$sku_map = $goodsSkuMapModel->field('sku_code_quantity,is_virtual_send')->where([
						'channel_id' => $order['channel_id'],
						'account_id' => $order['channel_account_id'],
						'channel_sku' => trim($v['channel_sku'])
					])->find();
				}

				if (!empty($sku_map)) {
					//是否为虚拟仓发货的订单
					if ($sku_map['is_virtual_send'] > 0) {
						$order['prior'] = PackageType::VirtualWarehouse;
					}
					$sku_code_quantity = json_decode($sku_map['sku_code_quantity'], true);
					foreach ($sku_code_quantity as $key => $value) {
						$detail_list[$kk]['sku_id'] = $value['sku_id'];
						$detail_list[$kk]['sku_quantity'] = $channelSkuQuantity * $value['quantity'];
						$detail_list[$kk]['channel_sku'] = isset($v['channel_sku']) ? $v['channel_sku'] : '';
						$detail_list[$kk]['transaction_id'] = $v['transaction_id'] ?? '';
						$goods_sku_data = Cache::store('goods')->getSkuInfo($value['sku_id']);
						if (!empty($goods_sku_data)) {
							$this->assemblyDetail($order, $no_delivery_sku, $goods_sku_data, $v, $kk, $first_fee, $cost, $tariff, $detail_list);
						}
						$kk++;
					}

				} else {
					$afterInterceptSku = $this->matchSkuByDelimiter($v['channel_sku']);
					//匹配别名表
					$sku_alias = $goodsSkuAliasModel->field('sku_id')->where(['alias' => $afterInterceptSku])->find();
					if (!empty($sku_alias)) {
						$goods_sku_data = Cache::store('goods')->getSkuInfo($sku_alias['sku_id']);
					} else {
						$goods_sku_data = $goodsSkuModel->field(true)->where(['sku' => $afterInterceptSku])->find();
					}
					if (!empty($goods_sku_data)) {
						$detail_list[$kk]['channel_sku'] = isset($v['channel_sku']) ? $v['channel_sku'] : '';
						$detail_list[$kk]['sku_id'] = $goods_sku_data['id'];
						$this->assemblyDetail($order, $no_delivery_sku, $goods_sku_data, $v, $kk, $first_fee, $cost, $tariff, $detail_list);
					}
					$kk++;
				}

			}
			$order['first_fee'] = $first_fee;   //头程费
			$order['tariff'] = $tariff;   //关税
			$order['cost'] = $cost;  //成本价
			return $detail_list;
		} catch (Exception $e) {
			throw new OrderDetailException($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	private function assemblyDetail(&$order, $no_delivery_sku, $goods_sku_data, $v, $kk, &$first_fee, &$cost, &$tariff, &$detail_list)
	{
		$detail_list[$kk]['sku'] = $goods_sku_data['sku'];
		$detail_list[$kk]['goods_id'] = $goods_sku_data['goods_id'];
		$detail_list[$kk]['sku_title'] = $goods_sku_data['spu_name'];
		$detail_list[$kk]['sku_thumb'] = $goods_sku_data['thumb'];
		if (isset($v['channel_sku_price']) && isset($order['rate'])) {
			$detail_list[$kk]['sku_price'] = $v['channel_sku_price'] * $order['rate'];
		} else {
			$detail_list[$kk]['sku_price'] = $goods_sku_data['retail_price'];
		}
		$detail_list[$kk]['channel_item_id'] = $v['channel_item_id'];
		$detail_list[$kk]['cost_price'] = $goods_sku_data['cost_price'];
		$detail_list[$kk]['weight'] = $goods_sku_data['weight'];
		$detail_list[$kk]['publish_id'] = isset($goods_sku_data['creator_id']) ? $goods_sku_data['creator_id'] : 0;
		//查找产品，获取头程费
		$goodsInfo = $this->getGoods($goods_sku_data['goods_id']);
		if (isset($goodsInfo['pre_sale']) && $goodsInfo['pre_sale'] == 1) {
			$detail_list[$kk]['tag'] = 1; //标签 0-正常 1-空卖
		}
		//产品的一些重量，体积信息
		$this->goodsDetailInfo($detail_list, $kk, $goodsInfo);
		$first_fee += !empty($goodsInfo) ? $goodsInfo['first_fee'] * $detail_list[$kk]['sku_quantity'] : 0;
		$cost += !empty($goods_sku_data) ? $goods_sku_data['cost_price'] * $detail_list[$kk]['sku_quantity'] : 0;
		$tariff += !empty($goodsInfo) ? $goodsInfo['tariff'] * $detail_list[$kk]['sku_quantity'] : 0;
		if ($no_delivery_sku && $no_delivery_sku == $detail_list[$kk]['sku']) {
			$order['belong_type'] = 3;
		}

        // 查询掉价处理
        $res = (new OrderHelp())->subsidyVerification($order['pay_time'], $goodsInfo['warehouse_id'], $detail_list[$kk]['sku_id'], $detail_list[$kk]['sku_quantity'], $detail_list[$kk]['sku_price']);
        if($res['status'] == 1){
            $detail_list[$kk]['subsidy_price'] = $res['falling_price'];
            $detail_list[$kk]['subsidy_quantity'] = $res['matches_num'];
        }
	}

	/**
	 * 获取产品的成本价信息
	 * @param $sku_id
	 * @param $warehouse_id
	 * @return float|int
	 * @throws Exception
	 */
	public function getGoodsCostPriceBySkuId($sku_id, $warehouse_id)
	{
		//查看库存表获取平均成本(不包含运费)
		$warehouseGoodsService = new WarehouseGoods();
		$cost_price = $warehouseGoodsService->getCostPrice($warehouse_id, $sku_id);
		if (empty($cost_price)) {
			//查供应商报价
			$supplierOfferService = new SupplierOfferService();
			if (is_numeric($sku_id)) {
				$cost_price = $supplierOfferService->getGoodsOffer($sku_id);
				if (empty($cost_price)) {
					//查询产品表
					$goods_sku_data = Cache::store('goods')->getSkuInfo($sku_id);
					if (floatval($goods_sku_data['cost_price']) == 0) {
						// Cache::handler()->hSet('hash:order:getGoods:' . date('Ymd') . ':' . date('H'), $sku_id . '-' . $warehouse_id . '-' . date('Y-m-d H:i:s'), $cost_price);
					}
					return $goods_sku_data['cost_price'] ?? 0;
				}
			} else {
				$goods_sku_data = Cache::store('goods')->getSkuInfo($sku_id);

				if (floatval($goods_sku_data['cost_price']) == 0) {
					// Cache::handler()->hSet('hash:order:getGoods:' . date('Ymd') . ':' . date('H'), $sku_id . '-' . $warehouse_id . '-' . date('Y-m-d H:i:s'), $cost_price);
				}
				return $goods_sku_data['cost_price'] ?? 0;
			}
		}
		if (floatval($cost_price) == 0) {
			// Cache::handler()->hSet('hash:order:getGoods:' . date('Ymd') . ':' . date('H'), $sku_id . '-' . $warehouse_id . '-' . date('Y-m-d H:i:s'), $cost_price);
		}
		return $cost_price;
	}

	/**
	 * 重置sku详情的成本价，计算订单总的成本价
	 * @param $detailList
	 * @param $warehouse_id
	 * @return float|int
	 */
	public function setGoodsCostPrice(&$detailList, $warehouse_id)
	{
		$cost = 0;
		foreach ($detailList as $key => &$detail) {
			if (!isset($detail['sku_cost']) || empty(floatval($detail['sku_cost']))) {
				$cost_price = $this->getGoodsCostPriceBySkuId($detail['sku_id'], $warehouse_id);
				$detail['cost_price'] = $cost_price;
				$detail['cost'] = $cost_price;
				$detail['costPrice'] = $cost_price * $detail['sku_quantity'];
				$cost += $detail['costPrice'];
			} else {
				$cost_price = $detail['sku_cost'];
				$detail['cost_price'] = $cost_price;
				$detail['cost'] = $cost_price;
				$detail['costPrice'] = $cost_price * $detail['sku_quantity'];
				$cost += $detail['costPrice'];
			}
		}
		return $cost;
	}

	/** 记录产品的申报相关的信息
	 * @param $detail_list
	 * @param $k
	 * @param $goodsInfo
	 */
	public function goodsDetailInfo(&$detail_list, $k, $goodsInfo)
	{
		$detail_list[$k]['declare_name'] = $goodsInfo['declare_name'];
		$detail_list[$k]['declare_en_name'] = $goodsInfo['declare_en_name'];
		$detail_list[$k]['totalWeight'] = $detail_list[$k]['weight'] * $detail_list[$k]['sku_quantity'];
		$detail_list[$k]['volume'] = $goodsInfo['width'] * $goodsInfo['height'] * $goodsInfo['depth'];
		$detail_list[$k]['totalVolume'] = $detail_list[$k]['volume'] * $detail_list[$k]['sku_quantity'];
		$detail_list[$k]['price'] = $detail_list[$k]['sku_price'];
		$detail_list[$k]['hs_code'] = $goodsInfo['hs_code'];
		$detail_list[$k]['totalPrice'] = $detail_list[$k]['price'] * $detail_list[$k]['sku_quantity'];
		$detail_list[$k]['cost'] = $detail_list[$k]['cost_price'];
		$detail_list[$k]['costPrice'] = $detail_list[$k]['cost_price'] * $detail_list[$k]['sku_quantity'];
	}

	/**
	 * 包装费用计算
	 * @param array $packageList 【包裹信息】
	 * @param array $detailList 【详情信息】
	 * @param array $order 【详情信息】
	 * @return array
	 * @throws OrderPackageException
	 */
	public function bagging($packageList, $detailList, &$order)
	{
		try {
			$goodsSkuModel = new GoodsSku();
			$packingModel = new Packing();
			$newPackage = [];
			$packageAmount = 0;
			$configData = Cache::store('configParams')->getConfig('packing_cost');  //查看默认的包裹费用
			//查看包裹信息,找出包裹中有多少产品
			foreach ($packageList as $key => $value) {
				$skuIds = [];
				foreach ($detailList as $k => $v) {
					if (in_array($v['sku_id'], $value['contains'])) {
						array_push($skuIds, $v['sku_id']);
					}
				}
				$length = 0;   //长度
				$height = 0;    //高度
				$sLength = 0;   //剩余长度
				$sWidth = 0;   //剩余宽度
				$weight = 0;   //重量
				$width = 0;  //宽度
				//所有的包裹产品都有了
				if (!empty($skuIds)) {
					$where['id'] = ['in', $skuIds];
					$skuList = $goodsSkuModel->field('length,height,width,weight')->where($where)->order('length desc,width desc,height desc')->select();
					if (!empty($skuList)) {
						$length = $skuList[0]['length'];
					}
					//求出最宽的
					$width = $this->min($skuList);
					$maxLength = 0;   //记录商品最大的长度
					//查看高度要多高
					$i = 0;
					foreach ($skuList as $k => $v) {
						if ($i == 0) {
							$height += $v['height'];
							$sLength = $length;
							$sWidth = $width;
						} else {
							if ($v['length'] > $maxLength) {
								$maxLength = $v['length'];
							}
							$lengthValue = $sLength - $v['length'];
							$widthValue = $sWidth - $v['width'];
							if ($widthValue < 0) {
								if ($width > $v['length'] || $width == $v['length']) {
									if (($length - $maxLength) >= $v['width']) {
										$sLength = $length - $maxLength - $v['width'];
									} else {
										$height += $v['height'];
									}
								} else {
									$height += $v['height'];
								}
							} else {
								$sLength = $lengthValue;
								$sWidth = $widthValue;
							}
						}
						$weight += $v['weight'];
						$i++;
					}
					//得出长宽高,查看包装材料表
					$whereExp['depth'] = ['>=', $length];
					$whereExp['width'] = ['>=', $width];
					$whereExp['height'] = ['>=', $height];
					$packingInfo = $packingModel->field('currency_code,cost_price,id')->where($whereExp)->order('depth asc,width asc,height asc')->limit(1)->select();
					if (!empty($packingInfo)) {
						if (isset($configData['value']) && !empty($configData['value'])) {
							$value['package_fee'] = $configData['value'];
						} else {
							$rate = Cache::store('currency')->getCurrency($packingInfo[0]['currency_code']);
							$value['package_fee'] = !empty($rate) ? $packingInfo[0]['cost_price'] * $rate['system_rate'] : 1;
						}
						$value['packing_id'] = $packingInfo[0]['id'];
						$packageAmount += $value['package_fee'];
					} else {
						if (isset($configData['value']) && !empty($configData['value'])) {
							$value['package_fee'] = $configData['value'];
						}
					}
				}
				$value['length'] = $length;
				$value['width'] = $width;
				$value['height'] = $height;
				array_push($newPackage, $value);
			}
			$order['package_fee'] = $packageAmount;
			return $newPackage;
		} catch (Exception $e) {
			throw new OrderPackageException($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	/**
	 * 获取多个sku组合包裹的体积
	 * @param array $skuIds
	 * @return float|int
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\ModelNotFoundException
	 * @throws \think\exception\DbException
	 */
	public function getVolume(array $skuIds)
	{
		$goodsSkuModel = new GoodsSku();
		$length = 0;   //长度
		$height = 0;    //高度
		$sLength = 0;   //剩余长度
		$sWidth = 0;   //剩余宽度
		$width = 0;  //宽度
		//所有的包裹产品都有了
		if (!empty($skuIds)) {
			$where['id'] = ['in', $skuIds];
			$skuList = $goodsSkuModel->field('height,length,width')->where($where)->order('length desc,width desc,height desc')->select();
			if (!empty($skuList)) {
				$length = $skuList[0]['length'];
			}
			//求出最宽的
			$width = $this->min($skuList);
			$maxLength = 0;   //记录商品最大的长度
			//查看高度要多高
			$i = 0;
			foreach ($skuList as $k => $v) {
				if ($i == 0) {
					$height += $v['height'];
					$sLength = $length;
					$sWidth = $width;
				} else {
					if ($v['length'] > $maxLength) {
						$maxLength = $v['length'];
					}
					$lengthValue = $sLength - $v['length'];
					$widthValue = $sWidth - $v['width'];
					if ($widthValue < 0) {
						if ($width > $v['length'] || $width == $v['length']) {
							if (($length - $maxLength) >= $v['width']) {
								$sLength = $length - $maxLength - $v['width'];
							} else {
								$height += $v['height'];
							}
						} else {
							$height += $v['height'];
						}
					} else {
						$sLength = $lengthValue;
						$sWidth = $widthValue;
					}
				}
				$i++;
			}
		}
		$volume = $length * $width * $height;
		return $volume;
	}

	/** 取最大值
	 * @param array $data
	 * @return int
	 */
	private function min(array $data)
	{
		$min = 0;
		$len = count($data);
		for ($i = 0; $i < $len; $i++) {
			if ($i == 0) {
				$min = $data[$i]['width'];
				continue;
			}
			if ($data[$i]['width'] > $min) {
				$min = $data[$i]['width'];
			}
		}
		return $min;
	}

	/** 合包裹判断
	 * @param $order
	 * @param $packageInfo
	 * @return array
	 * @throws OrderPackageException
	 */
	private function combinationPackage($order, $packageInfo)
	{
		try {
			$where['channel_id'] = ['eq', $order['channel_id']];
			$where['channel_account_id'] = ['eq', $order['channel_account_id']];
			$where['buyer_id'] = ['eq', $order['buyer_id']];
			$orderAddressModel = new OrderAddress();
			$model = new OrderModel();
			$packageModel = new OrderPackage();
			$packageService = new PackageService();
			$orderAddressList = $orderAddressModel->field(true)->where($where)->select();
			//符合条件的订单id
			$accord = [];
			if (!empty($orderAddressList)) {
				//证明有 没发货的单
				foreach ($orderAddressList as $k => $v) {
					if ($v['order_id'] == $order['id']) {
						continue;
					}
					if (!$this->checkAddress($v, $order)) {
						continue;
					}
					//查看订单是否已经配货了   --之前是发货的,发货的有上传仓库和物流一系列的问题，为了简化，直接判断 是否已配货好了
					$orderWhere['status'] = ['<=', OrderStatusConst::ForDistribution];   //未配货的
					$orderWhere['distribution_time'] = ['eq', 0];   //未配货的
					$orderWhere['pay_time'] = ['>', 0];   //已付款的
					if ($packageInfo['channel_id'] == ChannelAccountConst::channel_wish) {
						$orderWhere['is_wish_express'] = ['eq', $order['is_wish_express']];
						$orderWhere['requires_delivery_confirmation'] = ['eq', $order['requires_delivery_confirmation']];
					}
					$channel_account = $packageInfo['channel_id'] * OrderType::ChannelVirtual + $packageInfo['channel_account_id'];
					$info = $model->where(['id' => $v['order_id'], 'channel_account' => $channel_account])->where($orderWhere)->count();
					if (!empty($info)) {
						//检查包裹是否又多个包裹
						$package_ids = (new PackageService())->getPackageIdByOrderId($v['order_id']);
						if (count($package_ids) > 1) {
							continue;
						}
						//证明有没有没配货的单，查看包裹的是否同一个物流，同一个仓库
						$packageWhere['warehouse_id'] = ['eq', $packageInfo['warehouse_id']];
						$packageWhere['picking_id'] = ['eq', 0];             //未生成拣货单
						$packageWhere['package_upload_status'] = ['eq', 0];  // 物流未上传
						$packageList = $packageModel->field('id')->where(['order_id' => $v['order_id']])->where($packageWhere)->select();
						$combination = true;
						//$parcel = [];
						if (!empty($packageList)) {
							//多个包裹的订单不进行合并
							if (count($packageList) > 1) {
								continue;
							}
							if ($combination) {
								foreach ($packageList as $p => $pp) {
									$order_ids = $packageService->getOrderIdsByPackageId($pp['id']);
									$accord = array_merge($accord, $order_ids);
								}
							}
						}
					}
				}
			}
			return $accord;
		} catch (Exception $e) {
			throw new OrderPackageException($e->getMessage());
		}
	}

	/**
	 * 检查地址
	 * @param $address
	 * @param $order
	 * @return bool
	 */
	public function checkAddress($address, $order)
	{
		//查询本地库里 有没有 同一个买家 同收货地址,同一个物流，同一个仓库 ，还没有发货的订单
		if ($address['consignee'] == $order['consignee'] && $address['country_code'] == $order['country_code'] && $address['city'] == $order['city'] && $address['province'] == $order['province'] &&
			$address['address'] == $order['address'] && $address['address2'] == $order['address2'] && $address['zipcode'] == $order['zipcode'] && $address['tel'] == $order['tel'] && $address['mobile'] == $order['mobile']
			&& $address['email'] == $order['email']
		) {
			return true;
		}
		return false;
	}

	/** 匹配查找产品信息
	 * @param $order
	 * @param $sku
	 * @return array|false|\PDOStatement|string|\think\Model
	 */
	public function matchGoods($order, $sku)
	{
		$goods_sku_data = $this->matchGoodsSku($order, $sku);
		$goodsInfo = [];
		if (!empty($goods_sku_data)) {
			//查找产品信息
			foreach ($goods_sku_data as $g => $goods) {
				$goodsInfo = $this->getGoods($goods['goods_id']);
				break;
			}
		}
		return $goodsInfo;
	}

	/** 获取商品详情信息
	 * @param $goods_id
	 * @return array|false|\PDOStatement|string|\think\Model
	 */
	public function getGoods($goods_id)
	{
		$goodsInfo = Cache::store('goods')->getGoodsInfo($goods_id);
		return $goodsInfo;
	}

	/** 获取sku商品详情信息
	 * @param $sku_id
	 * @return array|false|\PDOStatement|string|\think\Model
	 */
	public function getSkuInfo($sku_id)
	{
		$skuInfo = Cache::store('goods')->getSkuInfo($sku_id);
		return $skuInfo;
	}

	/**
	 * 获取商品sku信息
	 * @param $order
	 * @param $sku
	 * @return array
	 * @throws Exception
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\ModelNotFoundException
	 * @throws \think\exception\DbException
	 */
	public function matchGoodsSku($order, $sku)
	{
		$goodsMapModel = new GoodsSkuMap();
		$goodsSkuModel = new GoodsSku();
		$goodsAliasModel = new GoodsSkuAlias();
		if ($order['channel_id'] == ChannelAccountConst::channel_Joom) {  //推送过来的是店铺，但是绑定的是账号
			$channelAccountId = Cache::store('JoomShop')->getAccountById($order['channel_account_id']);
			//匹配本地产品
			$sku_map = $goodsMapModel->field('sku_code_quantity')->where([
				'channel_id' => $order['channel_id'],
				'account_id' => $channelAccountId['joom_account_id'] ?? $order['channel_account_id'],
				'channel_sku' => trim($sku)
			])->find();
		} else {
			//匹配本地产品
			$sku_map = $goodsMapModel->field('sku_code_quantity')->field(true)->where([
				'channel_id' => $order['channel_id'],
				'account_id' => $order['channel_account_id'],
				'channel_sku' => trim($sku)
			])->find();
		}
		if (!empty($sku_map)) { //渠道匹配
			$sku_code_quantity = json_decode($sku_map['sku_code_quantity'], true);
			foreach ($sku_code_quantity as $key => $value) {
				$skuInfo = Cache::store('goods')->getSkuInfo($value['sku_id']);
				$skuInfo['sku_quantity'] = $value['quantity'];
				$goodsSkuData[] = $skuInfo;
			}
		} else {
			//截取sku
			$sku = $this->matchSkuByDelimiter($sku);
			//查看别名表
			$goods_alias_info = $goodsAliasModel->field(true)->where(['alias' => $sku])->find();
			if (!empty($goods_alias_info)) {
				$goodsSkuData[] = Cache::store('goods')->getSkuInfo($goods_alias_info['sku_id']);
			} else {
				$goods_sku = $goodsSkuModel->field(true)->where(['sku' => $sku])->find();
				if (!empty($goods_sku)) {
					$goodsSkuData[] = $goods_sku;
				}
			}
		}
		return !empty($goodsSkuData) ? $goodsSkuData : [];
	}

	/**
	 * 加上 分隔符匹配sku
	 * @param $sku
	 * @return array|false|\PDOStatement|string|\think\Model
	 */
	private function matchSkuByDelimiter1($sku)
	{
		$skuInfo = [];
		$goodsSkuModel = new GoodsSku();
		$this->setConfigIdentification('sku_split');
		$delimiter = $this->getConfigData();
		if (!empty($delimiter)) {
			if (strpos($sku, $delimiter) !== false) {  //包含分隔符
				$skuArr = explode($delimiter, $sku);
				$skuInfo = $goodsSkuModel->field(true)->where(['sku' => $skuArr[0]])->find();
			}
		}
		return $skuInfo;
	}

	/**
	 * 加上 分隔符匹配sku
	 * @param $sku
	 * @return bool|string
	 * @throws Exception
	 */
	public function matchSkuByDelimiter($sku)
	{
		$afterInterceptSku = $sku;
		$is_open = $this->getConfigData('intercept_is_open');
		if (!empty(intval($is_open['value']))) {  //开启截取
			$range = $this->getConfigData('intercept_range');
			switch (intval($range['value'])) {
				case 1:  //截取起始符与结束符之间的字符
					//获取起始字符
					$delimiter = $this->getConfigData('intercept_begin_char');
					$beginDelimiter = array_map(function ($delimiterChar) {
						foreach ($delimiterChar as $c => $value) {
							if (!empty($value)) {
								return $value['value'];
							}
						}
					}, $delimiter);
					//获取
					$delimiter = $this->getConfigData('intercept_end_char');
					$endDelimiter = array_map(function ($delimiterChar) {
						foreach ($delimiterChar as $c => $value) {
							if (!empty($value)) {
								return $value['value'];
							}
						}
					}, $delimiter);
					// 开始符
					$begin_char = $this->getConfigData('intercept_more_begin_char');
					$end_char = $this->getConfigData('intercept_more_end_char');
					$beginDelimiterNum = [];
					foreach ($beginDelimiter as $b => $char) {
						$num = strpos($sku, $char);
						if ($num !== false) {
							array_push($beginDelimiterNum, $num);
						}
					}
					$endDelimiterNum = [];
					foreach ($endDelimiter as $b => $char) {
						$num = strpos($sku, $char);
						if ($num !== false) {
							array_push($endDelimiterNum, $num);
						}
					}
					sort($beginDelimiterNum);
					sort($endDelimiterNum);
					$matchBeginPosition = intval($begin_char['value']) == 1 ? reset($beginDelimiterNum) : end($beginDelimiterNum);
					$matchEndPosition = intval($end_char['value']) == 1 ? reset($endDelimiterNum) : end($endDelimiterNum);
					$diff = $matchEndPosition - $matchBeginPosition;
					if ($diff < 0) {   // 结束符在起始符左侧
						$left_char = $this->getConfigData('intercept_end_char_left');
						switch (intval($left_char['value'])) {
							case 1:
								$afterInterceptSku = substr($sku, 0, reset($endDelimiterNum));
								break;
							case 2:
								$afterInterceptSku = substr($sku, end($endDelimiterNum) + 1);
								break;
						}
					} else {
						if ($matchBeginPosition === false && $matchEndPosition !== false) {
							$afterInterceptSku = substr($sku, 0, $matchEndPosition);
						} else if ($matchBeginPosition !== false && $matchEndPosition === false) {
							$afterInterceptSku = substr($sku, $matchBeginPosition + 1);
						} else if ($matchBeginPosition === false && $matchEndPosition === false) {
							$afterInterceptSku = $sku;
						} else {
							if (($matchEndPosition - $matchBeginPosition - 1) > 0) {
								$afterInterceptSku = substr($sku, $matchBeginPosition + 1, $matchEndPosition - $matchBeginPosition - 1);
							}
						}
					}
					break;
				case 2:  //截取从固定位置起止的字符
					$charData = $this->getConfigData('intercept_forward');
					if ($charData > 0) {
						$afterInterceptSku = substr($sku, 0, $charData);
						break;
					}
					$charData = $this->getConfigData('intercept_backward');
					if ($charData > -1) {
						$afterInterceptSku = substr($sku, $charData);
						break;
					}
					$charData = $this->getConfigData('intercept_middle');
					if (!empty($charData)) {
						list($b_char, $e_char) = explode(',', $charData);
						$afterInterceptSku = substr($sku, $b_char, $e_char);
					}
					break;
			}
		}
		return $afterInterceptSku;
	}

	/**
	 * 重新审查订单（重新跑规则）
	 * @param array $orderList
	 * @param $orderModel
	 * @return array
	 * @throws Exception
	 * @throws OrderException
	 * @throws \Exception
	 */
	public function pending(array $orderList, $orderModel)
	{
		$success = [];   //成功
		$failure = [];   //失败
		$detailModel = new OrderDetail();
		$addressModel = new OrderAddress();
		$orderPackageModel = new OrderPackage();
		$declareService = new DeclareService();
		$user = Common::getUserInfo();
		foreach ($orderList as $order => $orderId) {
			if (!is_numeric($orderId)) {
				array_push($failure, $orderId);
				continue;
			}
			try {
				//查询订单的信息
				$orderInfo = $orderModel->field('channel_account', true)->where(['id' => $orderId])->find();
				$log = [
					0 => [
						'message' => '订单重新核查',
						'operator' => '系统自动',
						'process_id' => $orderInfo['status']
					]
				];
				if (!empty($orderInfo)) {
					//地址信息
					$orderInfo = $orderInfo->toArray();
					$address = $addressModel->field('id', true)->where(['order_id' => $orderInfo['id']])->find();
					if (empty($address)) {
						array_push($failure, $orderId);
						continue;
					}
					$address = $address->toArray();
					$order_info = array_merge($orderInfo, $address);
					//获取详情信息
					$detail = $detailModel->field(true)->where(['order_id' => $orderInfo['id']])->select();
					if (!empty($detail)) {
						$is_merge = false;  //是否为合并
						$first_fee = 0;
						$cost = 0;
						$tariff = 0;
						$detail_list = [];
						foreach ($detail as $k => $dd) {
							$dd = $dd->toArray();
							$detail_list[$k] = $dd;
							//查找产品，获取头程费
							$goodsInfo = $this->getGoods($dd['goods_id']);
							$goods_sku_data = Cache::store('goods')->getSkuInfo($dd['sku_id']);
							$detail_list[$k]['cost_price'] = $goods_sku_data['cost_price'];
							$detail_list[$k]['weight'] = $goods_sku_data['weight'];
							$detail_list[$k]['publish_id'] = isset($goods_sku_data['creator_id']) ? $goods_sku_data['creator_id'] : 0;
							//产品的一些重量，体积信息
							$this->goodsDetailInfo($detail_list, $k, $goodsInfo);
							$first_fee += !empty($goodsInfo) ? $goodsInfo['first_fee'] * $dd['sku_quantity'] : 0;
							$cost += !empty($goods_sku_data) ? $goods_sku_data['cost_price'] * $dd['sku_quantity'] : 0;
							$tariff += !empty($goodsInfo) ? $goodsInfo['tariff'] * $dd['sku_quantity'] : 0;
						}
						$order_info['first_fee'] = $first_fee;   //头程费
						$order_info['tariff'] = $tariff;   //关税
						$order_info['cost'] = $cost;
						$order_info['warehouse_id'] = 0;
						$orderPackage = $this->getPackage($detail_list, $order_info);
						$orderNewPackage = [];
						//检查规则
						foreach ($orderPackage as $o => $oo) {
							$temp = $oo;
							OrderRuleCheckService::checkRule($temp, $detail_list, $log);
							$order_info = $temp;
							array_push($orderNewPackage, $temp);
						}
						$packageDeclareAll = [];   //申报价格信息
						$packageGoodsAll = [];  //包裹商品
						$packageArray = [];    //包裹信息
						//更新订单
						foreach ($orderNewPackage as $p => $pp) {
							if (is_object($pp)) {
								$pp = $pp->toArray();
							}
							$packageGoods = [];
							//判断是否要合包裹
							if (count($orderNewPackage) > 1) {
								$accord = [];
							} else {
								$accord = $this->combinationPackage($order, $pp);
							}
							if (!empty($accord)) {
								$mergeStatus = $pp['status'];
								//合并包裹
								$detail_list = $this->mergePackage($pp, $accord, $order, $detail_list, $packageGoods, $log, $mergeStatus);
								$is_merge = true;
								$merge_log = [
									'message' => '订单合并了包裹，重新跑物流方式规则',
									'operator' => '系统自动',
									'process_id' => 0
								];
								array_push($log, $merge_log);
								$pp['status'] = $mergeStatus;
								//重新跑物流规则
								OrderRuleCheckService::checkRule($pp, $detail_list, $log, 2);
								$newStatus = $this->mergeOrderStatus($mergeStatus, $pp['status']);   //状态可能变化了
								$pp['status'] = $newStatus;
							} else {
								//插入新的包裹信息
								$pp['order_id'] = $orderId;
								$pp['id'] = ServiceTwitter::instance()->nextId($order_info['channel_id'],
									$order_info['channel_account_id']);
							}
							foreach ($detail_list as $d => $dd) {
								$list = $dd;
								//看看这个商品是属于哪个包裹的
								if (isset($pp['contains'])) {
									if (in_array($dd['sku_id'], $pp['contains'])) {
										$list['package_id'] = $pp['id'];
										if (!isset($list['id'])) {
											$list['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
												$order['channel_account_id']);
											$list['order_id'] = $order['id'];
										}
										array_push($packageGoods, $dd);
										array_push($packageGoodsAll, $list);
									}
								} else {
									$list['package_id'] = $pp['id'];
									if (!isset($list['id'])) {
										$list['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
											$order['channel_account_id']);
										$list['order_id'] = $order['id'];
										array_push($packageGoods, $dd);
									}
									array_push($packageGoodsAll, $list);
								}
							}
							//包裹申报
							$packageDeclare = $declareService->matchDeclare($pp, $packageGoods, $log);
							$packageDeclareAll[$pp['id']] = $packageDeclare;
							//记录包裹号
							$packageArray[$pp['id']] = $pp;
						}
						//更新原来的订单信息
						if (isset($order_info['type'])) {
							unset($order_info['type']);
						}
						Db::startTrans();
						try {
							//删除原来的详情
							$detailModel->where(['order_id' => $order_info['id']])->delete();
							//新增
							$detailModel->allowField(true)->isUpdate(false)->saveAll($packageGoodsAll, false);
							foreach ($packageArray as $package => $pp) {
								//包裹申报
								$declareService->storage($packageDeclareAll[$pp['id']]);
								if ($is_merge) {
									$orderPackageModel->allowField(true)->isUpdate(true)->save($pp);
									//更改订单状态
									$orderModel->where('id', 'in', [$orderInfo['id'], $pp['order_id']])->update(['status' => $pp['status']]);
									//记录日志
									CommonService::addOrderLog($pp['order_id'], '订单包裹合并了' . $orderInfo['order_number'] . '的包裹',
										'系统自动');
								} else {
									//记录包裹统计信息
									if (isset($pp['warehouse_id']) && isset($pp['shipping_id'])) {
										ReportService::saleByPackage($pp['channel_id'], $pp['warehouse_id'],
											$pp['shipping_id'],
											$order_info['country_code'], $order_info['create_time'], [
												'generated' => 1
											]);
									}
								}
								//记录匹配的规则id
								$this->recordRule($pp);
							}
							if (!$is_merge) {
								$orderPackageModel->allowField(true)->isUpdate(false)->saveAll($packageArray, false);
							}
							$orderModel->allowField(true)->save($order_info, ['id' => $orderId]);
							//是否新增备注
							$this->note($order_info);
							//插入日志
							foreach ($log as $g => $gg) {
								if (isset($user['realname'])) {
									CommonService::addOrderLog($orderId, $gg['message'], '系统自动',
										$gg['process_id']);
								} else {
									CommonService::addOrderLog($orderId, $gg['message'], '系统自动',
										$gg['process_id']);
								}
							}
							//统计信息
							foreach ($packageGoodsAll as $detail => $list) {
								if ($order_info['status'] == OrderStatusConst::ForDistribution) {
									$this->countReport($order_info, $list, $packageArray[$list['package_id']]);
								}
							}
							Db::commit();
							array_push($success, $orderId);
						} catch (Exception $e) {
							Db::rollback();
							CommonService::addOrderLog($orderId,
								'订单重新核查失败，失败原因:' . $e->getMessage() . $e->getFile() . $e->getLine(), '系统自动', 0);
							throw new OrderException($e->getMessage() . $e->getFile() . $e->getLine());
						}
						$log = [];
						//自动配货
						if ($order_info['status'] == OrderStatusConst::ForDistribution) {
							$this->autoDistribution($order_info, $packageArray, $log, $detailModel);
						}
					} else {
						array_push($failure, $orderId);
					}
				} else {
					array_push($failure, $orderId);
				}
			} catch (OrderException $e) {
				array_push($failure, $orderId);
				throw new OrderException($e->getMessage());
			} catch (Exception $e) {
				CommonService::addOrderLog($orderId,
					'订单重新核查失败,失败原因：' . $e->getMessage() . $e->getFile() . $e->getLine(), '系统自动', 0);
				array_push($failure, $orderId);
			}
		}
		//返回数据
		return ['success' => count($success), 'failure' => count($failure)];
	}

	/**
	 * 接收fba单
	 * @param array $orderList
	 * @return array
	 * @throws \Exception
	 */
	public function addFba(array $orderList)
	{
		$success = [];  //成功
		$failure = [];  //失败
		$fbaOrderModel = new FbaOrder();
		$fbaOrderDetailModel = new FbaOrderDetail();
		$fbaOrderSourceDetailModel = new FbaOrderSourceDetail();
		$warehouseService = new Warehouse();
		foreach ($orderList as $key => $value) {
			if (!isset($value['order']) || !isset($value['orderDetail'])) {
				continue;
			}
			$order = $value['order'];
			$detail = $value['orderDetail'];
			try {
				if (empty($order) || empty($detail)) {
					continue;
				}
				$currency = new Currency();
				$rate = $currency->getCurrency($order['currency_code']);
				if (!isset($rate[$order['currency_code']])) {
					$this->recordResult($order, false);
					$this->fba_log($order['channel_order_id'] . '---' . $order['channel_id'] . '---' . $order['currency_code'] . '找不到对应的汇率');
					continue;
				}
				//获取销售员
				$this->sales($order);
				//平台费用
				$this->cost($order);
				$order['rate'] = $rate[$order['currency_code']] ?? 0;
				$order['create_time'] = time();
				$order['order_number'] = trim($order['order_number']);
				//订单详情
				$detail_list = $this->fbaDetail($detail, $order);
				$order['id'] = ServiceTwitter::instance()->nextId($order['channel_id'], $order['channel_account_id']);
				$source = [];
				$newDetail = [];
				//插入来源信息
				foreach ($detail as $s => $ss) {
					$ss['fba_order_id'] = $order['id'];
					$ss['id'] = ServiceTwitter::instance()->nextId($order['channel_id'], $order['channel_account_id']);
					array_push($source, $ss);
					if (isset($detail_list[$s])) {
						$detail_list[$s]['fba_order_source_id'] = $ss['id'];
						$detail_list[$s]['fba_order_id'] = $order['id'];
						$detail_list[$s]['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
							$order['channel_account_id']);
						array_push($newDetail, $detail_list[$s]);
					}
					$order['warehouse_id'] = $warehouseService->getWarehouseIdByAccountId($order['channel_account_id']);
					Db::startTrans();
					try {
						$fbaOrderModel->allowField(true)->isUpdate(false)->save($order);
						$fbaOrderSourceDetailModel->allowField(true)->isUpdate(false)->saveAll($source, false);
//                    foreach ($newDetail as $k => $v) {
//                        //记录fba产品统计信息
//                        ReportService::saleByGoods($order['channel_id'], $v['goods_id'], $v['sku_id'], $order['warehouse_id'],
//                            $order['create_time'], [
//                                'order' => $v['sku_quantity'],
//                                'buyer' => 1,   //买家数
//                                'turnover' => 1  //订单笔数
//                            ],false);
//                    }
						//统计fba销售额与订单数
						ReportService::saleByDeeps($order['channel_id'], $order['site'], $order['warehouse_id'], $order['channel_account_id'], [
							'sale' => $order['pay_fee'] * $order['rate'],
							'delivery' => 1
						]);
						$fbaOrderDetailModel->allowField(true)->isUpdate(false)->saveAll($newDetail, false);
						Db::commit();
						array_push($success, $order['channel_order_id']);
					} catch (Exception $e) {
						Db::rollback();
						throw new OrderException($e->getMessage() . $e->getFile() . $e->getLine());
					}
				}
			} catch (Exception $e) {
				$this->fba_log($order['channel_order_id'] . $e->getMessage() . $e->getFile() . $e->getLine());
				array_push($failure, $order['channel_order_id']);
			}
		}
		return ['success' => $success, 'failure' => $failure];
	}

	/** 记录失败日志
	 * @param $message
	 */
	private
	function fba_log($message)
	{
		$fileName = date('Y-m-d', time());
		$logFile = LOG_PATH . "order/" . $fileName . "fba_failure.log";
		file_put_contents($logFile, '-----' . $message . "\r\n", FILE_APPEND);
	}

	/** fba订单详情
	 * @param $detail
	 * @param $order
	 * @return array
	 * @throws Exception
	 * @throws OrderDetailException
	 */
	private function fbaDetail($detail, &$order)
	{
		try {
			$first_fee = 0;
			$cost = 0;
			$warehouseService = new Warehouse();
			$goodsSkuMapModel = new GoodsSkuMap();
			$goodsSkuModel = new GoodsSku();
			$goodsSkuAliasModel = new GoodsSkuAlias();
			$detail_list = [];
			foreach ($detail as $k => $v) {
				if (isset($v['sku_quantity'])) {
					$detail_list[$k]['sku_quantity'] = $v['sku_quantity'];
				}
				//匹配本地产品
				$sku_map = $goodsSkuMapModel->where([
					'channel_id' => $order['channel_id'],
					'account_id' => $order['channel_account_id'],
					'channel_sku' => trim($v['channel_sku'])
				])->find();
				$warehouse_id = $warehouseService->getWarehouseIdByAccountId($order['channel_account_id']);
				if (!empty($sku_map)) {
					$sku_code_quantity = json_decode($sku_map['sku_code_quantity'], true);
					foreach ($sku_code_quantity as $key => $value) {
						$detail_list[$k]['sku'] = $value['sku_code'];
						$detail_list[$k]['sku_id'] = $value['sku_id'];
						//订单成本
						$cost += $this->getGoodsCostPriceBySkuId($value['sku_id'], $warehouse_id);
						//头程费
						$goods_sku_data = Cache::store('goods')->getSkuInfo($value['sku_id']);
						$goodsInfo = $this->getGoods($goods_sku_data['goods_id']);
						$first_fee += !empty($goodsInfo) ? $goodsInfo['first_fee'] * $v['sku_quantity'] : 0;
						$goods_sku_data = Cache::store('goods')->getSkuInfo($value['sku_id']);
						if (!empty($goods_sku_data)) {
							$detail_list[$k]['sku_title'] = $goods_sku_data['spu_name'];
							$detail_list[$k]['goods_id'] = $goods_sku_data['goods_id'];
						}
					}
				} else {
					//匹配别名表
					$sku_alias = $goodsSkuAliasModel->where(['alias' => $v['channel_sku']])->find();
					if (!empty($sku_alias)) {
						$goods_sku_data = Cache::store('goods')->getSkuInfo($sku_alias['sku_id']);
					} else {
						$goods_sku_data = $goodsSkuModel->where(['sku' => $v['channel_sku']])->find();
					}
					if (!empty($goods_sku_data)) {
						$detail_list[$k]['sku'] = $goods_sku_data['sku'];
						$detail_list[$k]['sku_id'] = $goods_sku_data['id'];
						$detail_list[$k]['goods_id'] = $goods_sku_data['goods_id'];
						$detail_list[$k]['sku_title'] = $goods_sku_data['spu_name'];
						//订单成本
						$cost += $this->getGoodsCostPriceBySkuId($goods_sku_data['id'], $warehouse_id);
						//头程费
						$goods_sku_data = Cache::store('goods')->getSkuInfo($goods_sku_data['id']);
						$goodsInfo = $this->getGoods($goods_sku_data['goods_id']);
						$first_fee += !empty($goodsInfo) ? $goodsInfo['first_fee'] * $v['sku_quantity'] : 0;
					}
				}
			}
			$order['first_fee'] = $first_fee;   //头程费
			$order['cost'] = $cost;  //成本价
			return $detail_list;
		} catch (Exception $e) {
			throw new OrderDetailException($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	/**
	 * 接收fbs单
	 * @param array $orderList
	 * @return array
	 * @throws \Exception
	 */
	public function addFbs(array $orderList)
	{
		$success = [];
		$failure = [];
		$fbsOrderModel = new FbsOrder();
		$fbsOrderDetailModel = new FbsOrderDetail();
		$fbaOrderSourceDetailModel = new FbsOrderSourceDetail();
		$warehouseService = new Warehouse();
		foreach ($orderList as $key => $value) {
			if (!isset($value['order']) || !isset($value['orderDetail'])) {
				continue;
			}
			$order = $value['order'];
			$detail = $value['orderDetail'];
			try {
				if (empty($order) || empty($detail)) {
					continue;
				}
				$currency = new Currency();
				$rate = $currency->getCurrency($order['currency_code']);
				if (!isset($rate[$order['currency_code']])) {
					$this->recordResult($order, false);
					$this->fbs_log($order['order_number'] . '---' . $order['channel_id'] . '---' .
						$order['currency_code'] . '找不到对应的汇率', $order);
					continue;
				}
				$order['to_cny_rate'] = $rate[$order['currency_code']] ?? 0;
				$order['create_time'] = time();
				$order['order_number'] = trim($order['order_number']);
				//订单详情
				$detail_list = $this->fbsDetail($detail, $order);
				//渠道费用
				$this->cost($order);
				$order['id'] = ServiceTwitter::instance()->nextId($order['channel_id'], $order['channel_account_id']);
				$source = [];
				$newDetail = [];
				//插入来源信息
				foreach ($detail as $s => $ss) {
					$ss['fbs_order_id'] = $order['id'];
					$ss['id'] = ServiceTwitter::instance()->nextId($order['channel_id'], $order['channel_account_id']);
					array_push($source, $ss);
					if (isset($detail_list[$s])) {
						$detail_list[$s]['fbs_order_source_id'] = $ss['id'];
						$detail_list[$s]['fbs_order_id'] = $order['id'];
						$detail_list[$s]['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
							$order['channel_account_id']);
						array_push($newDetail, $detail_list[$s]);
					}
				}
				$order['warehouse_id'] = 398;
				Db::startTrans();
				try {
					$fbsOrderModel->allowField(true)->isUpdate(false)->save($order);
					$fbaOrderSourceDetailModel->allowField(true)->isUpdate(false)->saveAll($source, false);
//                    统计fbs销售额与订单数
					ReportService::saleByDeeps($order['channel_id'], $order['site'], $order['warehouse_id'], $order['channel_account_id'],
						[
							'sale' => $order['pay_fee'] * $order['to_cny_rate'],
							'delivery' => 1
						]);
					$fbsOrderDetailModel->allowField(true)->isUpdate(false)->saveAll($newDetail, false);
					Db::commit();
					array_push($success, $order['channel_order_id']);
					$row = [];
					$warehouseGoods = new WarehouseGoods();
					foreach ($newDetail as $d) {
						$warehouseGoods->waiting_shipping_quantity(398, $d['sku_id'], $d['sku_quantity']);
						$skuInfo = [];
						$skuInfo['goods_id'] = $d['goods_id'] ?? $d['goods_id'];
						$skuInfo['sku_id'] = $d['sku_id'] ?? $d['sku_id'];
						$skuInfo['sku'] = $d['sku'] ?? $d['sku'];
						$skuInfo['quantity'] = $d['sku_quantity'] ?? intval($d['sku_quantity']);
						$skuInfo['actual_quantity'] = $d['sku_quantity'] ?? intval($d['sku_quantity']);
						$skuInfo['price'] = 0;
						$skuInfo['remark'] = '';
						$row['detail'][] = $skuInfo;
					}
					$stockOut = new StockOut();
					$stockOut->insert($order['warehouse_id'], 21, '', '', 2, $row['detail']);

				} catch (Exception $e) {
					Db::rollback();
					throw new OrderException($e->getMessage() . $e->getFile() . $e->getLine());
				}
			} catch (Exception $e) {
				$message = $order['order_number'] . '---' . $e->getMessage() . $e->getFile() . $e->getLine();
				$this->fbs_log($message, $order);
				array_push($failure, $order['channel_order_id']);
			}

		}
		return ['success' => $success, 'failure' => $failure];
	}

	/** 记录shopee海外仓推单失败日志
	 * @param $message
	 */
	private function fbs_log($message, $order)
	{
		$fileName = date('Y-m-d', time());
		$logFile = LOG_PATH . "order/" . $fileName . 'fbs_failure.log';
		$dir = LOG_PATH . "order";
		Cache::store('ShopeeOrder')->setFbsLog($order['order_number'], $message, $order['channel_account_id']);
		if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
			return;
		}
		file_put_contents($logFile, '-----' . $message . '------' . date('Ymd_His', time()) . "\r\n", FILE_APPEND);
	}


	/**
	 * 记录pandao海外仓推单失败日志
	 * @param $message
	 * @param $order
	 * @throws Exception
	 */
	private static function fbm_log($message, $order)
	{
		Cache::store('PandaoOrder')->setFbmLog($order['order_number'], $message, $order['channel_account_id']);
	}


	/**
	 * fbs SHOPEE 订单详情
	 * @param $detail
	 * @param $order
	 * @return array
	 * @throws OrderDetailException
	 */
	private function fbsDetail($detail, &$order)
	{
		try {

			$first_fee = 0;
			$cost = 0;
			$tariff = 0;
			$goodsSkuMapModel = new GoodsSkuMap();
			$goodsSkuModel = new GoodsSku();
			$goodsSkuAliasModel = new GoodsSkuAlias();
			$detail_list = [];
			foreach ($detail as $k => $v) {
				if (isset($v['sku_quantity'])) {
					$detail_list[$k]['sku_quantity'] = $v['sku_quantity'];
				}
				// 匹配本地产品
				$sku_map = $goodsSkuMapModel->where([
					'channel_id' => $order['channel_id'],
					'account_id' => $order['channel_account_id'],
					'channel_sku' => trim($v['channel_sku'])
				])->find();
				if (!empty($sku_map)) {
					$sku_code_quantity = json_decode($sku_map['sku_code_quantity'], true);
					foreach ($sku_code_quantity as $key => $value) {
						$detail_list[$k]['sku'] = $value['sku_code'];
						$detail_list[$k]['sku_id'] = $value['sku_id'];
						$goods_sku_data = Cache::store('goods')->getSkuInfo($value['sku_id']);
						if (!empty($goods_sku_data)) {
							$detail_list[$k]['sku_title'] = $goods_sku_data['spu_name'];
							$detail_list[$k]['goods_id'] = $goods_sku_data['goods_id'];
						}
						if (isset($v['channel_sku_price']) && isset($order['to_cny_rate'])) {
							$detail_list[$k]['sku_price'] = $v['channel_sku_price'] * $order['to_cny_rate'];
						} else {
							$detail_list[$k]['sku_price'] = $goods_sku_data['retail_price'];
						}
						$goodsInfo = $this->getGoods($goods_sku_data['goods_id']);
						/** 暂不记录产品的重量，体积等信息 */
						//产品的一些重量，体积信息
//                        $this->goodsDetailInfo($detail_list, $k, $goodsInfo);
						$first_fee += !empty($goodsInfo) ? $goodsInfo['first_fee'] * $detail_list[$k]['sku_quantity'] : 0;
						$cost += !empty($goods_sku_data) ? $goods_sku_data['cost_price'] * $detail_list[$k]['sku_quantity'] : 0;
						$tariff += !empty($goodsInfo) ? $goodsInfo['tariff'] * $detail_list[$k]['sku_quantity'] : 0;
					}
				} else {
					//匹配别名表
					$sku_alias = $goodsSkuAliasModel->where([
						'alias' => $v['channel_sku']
					])->find();
					if (!empty($sku_alias)) {
						$goods_sku_data = Cache::store('goods')->getSkuInfo($sku_alias['sku_id']);
					} else {
						$goods_sku_data = $goodsSkuModel->where([
							'sku' => $v['channel_sku']
						])->find();
					}
					if (!empty($goods_sku_data)) {
						$detail_list[$k]['sku'] = $goods_sku_data['sku'];
						$detail_list[$k]['sku_id'] = $goods_sku_data['id'];
						$detail_list[$k]['goods_id'] = $goods_sku_data['goods_id'];
						$detail_list[$k]['sku_title'] = $goods_sku_data['spu_name'];
						if (isset($v['channel_sku_price']) && isset($order['to_cny_rate'])) {
							$detail_list[$k]['sku_price'] = $v['channel_sku_price'] * $order['to_cny_rate'];
						} else {
							$detail_list[$k]['sku_price'] = $goods_sku_data['retail_price'];
						}
					}
					$goodsInfo = $this->getGoods($goods_sku_data['goods_id']);
					/** 暂不记录产品的重量，体积等信息 */
					//产品的一些重量，体积信息
//                    $this->goodsDetailInfo($detail_list, $k, $goodsInfo);
					$first_fee += !empty($goodsInfo) ? $goodsInfo['first_fee'] * $detail_list[$k]['sku_quantity'] : 0;
					$cost += !empty($goods_sku_data) ? $goods_sku_data['cost_price'] * $detail_list[$k]['sku_quantity'] : 0;
					$tariff += !empty($goodsInfo) ? $goodsInfo['tariff'] * $detail_list[$k]['sku_quantity'] : 0;
				}
			}
			$order['first_fee'] = $first_fee;   //头程费
			$order['tariff'] = $tariff;   //关税
			$order['product_cost'] = $cost;  //成本价

			return $detail_list;
		} catch (Exception $e) {
			throw new OrderDetailException($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	/**
	 * fbm pandao mymall海外仓 订单详情
	 * @param $detail
	 * @param $order
	 * @return array
	 * @throws OrderDetailException
	 */
	private function fbmDetail($detail, &$order)
	{
		try {

			$first_fee = 0;
			$cost = 0;
			$tariff = 0;
			$goodsSkuMapModel = new GoodsSkuMap();
			$goodsSkuModel = new GoodsSku();
			$goodsSkuAliasModel = new GoodsSkuAlias();
			$detail_list = [];
			foreach ($detail as $k => $v) {
				if (isset($v['sku_quantity'])) {
					$detail_list[$k]['sku_quantity'] = $v['sku_quantity'];
				}
				// 匹配本地产品
				$sku_map = $goodsSkuMapModel->where([
					'channel_id' => $order['channel_id'],
					'account_id' => $order['channel_account_id'],
					'channel_sku' => trim($v['channel_sku'])
				])->find();
				if (!empty($sku_map)) {
					$sku_code_quantity = json_decode($sku_map['sku_code_quantity'], true);
					foreach ($sku_code_quantity as $key => $value) {
						$detail_list[$k]['sku'] = $value['sku_code'];
						$detail_list[$k]['sku_id'] = $value['sku_id'];
						$goods_sku_data = Cache::store('goods')->getSkuInfo($value['sku_id']);
						if (!empty($goods_sku_data)) {
							$detail_list[$k]['sku_title'] = $goods_sku_data['spu_name'];
							$detail_list[$k]['goods_id'] = $goods_sku_data['goods_id'];
						}
						if (isset($v['channel_sku_price']) && isset($order['to_cny_rate'])) {
							$detail_list[$k]['sku_price'] = $v['channel_sku_price'] * $order['to_cny_rate'];
						} else {
							$detail_list[$k]['sku_price'] = $goods_sku_data['retail_price'];
						}
						$goodsInfo = $this->getGoods($goods_sku_data['goods_id']);
						/** 暂不记录产品的重量，体积等信息 */
						//产品的一些重量，体积信息
//                        $this->goodsDetailInfo($detail_list, $k, $goodsInfo);
						$first_fee += !empty($goodsInfo) ? $goodsInfo['first_fee'] * $detail_list[$k]['sku_quantity'] : 0;
						$cost += !empty($goods_sku_data) ? $goods_sku_data['cost_price'] * $detail_list[$k]['sku_quantity'] : 0;
						$tariff += !empty($goodsInfo) ? $goodsInfo['tariff'] * $detail_list[$k]['sku_quantity'] : 0;
					}
				} else {
					//匹配别名表
					$sku_alias = $goodsSkuAliasModel->where([
						'alias' => $v['channel_sku']
					])->find();
					if (!empty($sku_alias)) {
						$goods_sku_data = Cache::store('goods')->getSkuInfo($sku_alias['sku_id']);
					} else {
						$goods_sku_data = $goodsSkuModel->where([
							'sku' => $v['channel_sku']
						])->find();
					}
					if (!empty($goods_sku_data)) {
						$detail_list[$k]['sku'] = $goods_sku_data['sku'];
						$detail_list[$k]['sku_id'] = $goods_sku_data['id'];
						$detail_list[$k]['goods_id'] = $goods_sku_data['goods_id'];
						$detail_list[$k]['sku_title'] = $goods_sku_data['spu_name'];
						if (isset($v['channel_sku_price']) && isset($order['to_cny_rate'])) {
							$detail_list[$k]['sku_price'] = $v['channel_sku_price'] * $order['to_cny_rate'];
						} else {
							$detail_list[$k]['sku_price'] = $goods_sku_data['retail_price'];
						}
					}
					$goodsInfo = $this->getGoods($goods_sku_data['goods_id']);
					/** 暂不记录产品的重量，体积等信息 */
					//产品的一些重量，体积信息
//                    $this->goodsDetailInfo($detail_list, $k, $goodsInfo);
					$first_fee += !empty($goodsInfo) ? $goodsInfo['first_fee'] * $detail_list[$k]['sku_quantity'] : 0;
					$cost += !empty($goods_sku_data) ? $goods_sku_data['cost_price'] * $detail_list[$k]['sku_quantity'] : 0;
					$tariff += !empty($goodsInfo) ? $goodsInfo['tariff'] * $detail_list[$k]['sku_quantity'] : 0;
				}
			}
			$order['first_fee'] = $first_fee;   //头程费
			$order['tariff'] = $tariff;   //关税
			$order['product_cost'] = $cost;  //成本价

			return $detail_list;
		} catch (Exception $e) {
			throw new OrderDetailException($e->getMessage() . $e->getFile() . $e->getLine());
		}
	}

	/**
	 * 接收fbm pandao海外仓订单
	 * @param array $orderList
	 * @return array
	 * @throws \Exception
	 */
	public function addFbm(array $orderList)
	{
		$success = [];
		$failure = [];
		$fbmOrderModel = new FbmOrder();
		$fbmOrderDetailModel = new FbmOrderDetail();
		$fbmOrderSourceDetailModel = new FbmOrderSourceDetail();
		foreach ($orderList as $key => $value) {
			if (!isset($value['order']) || !isset($value['orderDetail'])) {
				continue;
			}

			$order = $value['order'];
			$detail = $value['orderDetail'];
			try {
				if (empty($order) || empty($detail)) {
					continue;
				}
				$currency = new Currency();
				$rate = $currency->getCurrency($order['currency_code']);
				if (!isset($rate[$order['currency_code']])) {
					$this->recordResult($order, false);
					$this->fbm_log($order['order_number'] . '---' . $order['channel_id'] . '---' .
						$order['currency_code'] . '找不到对应的汇率', $order);
					continue;
				}
				$order['to_cny_rate'] = $rate[$order['currency_code']] ?? 0;
				$order['create_time'] = time();
				$order['order_number'] = trim($order['order_number']);
				//订单详情
				$detail_list = $this->fbmDetail($detail, $order);
				$this->cost($order);
				$order['id'] = ServiceTwitter::instance()->nextId($order['channel_id'], $order['channel_account_id']);
				$source = [];
				$newDetail = [];
				//插入来源信息
				foreach ($detail as $s => $ss) {
					$ss['fbm_order_id'] = $order['id'];
					$ss['id'] = ServiceTwitter::instance()->nextId($order['channel_id'], $order['channel_account_id']);
					$ss['channel_sku_quantity'] = $ss['sku_quantity'];
					array_push($source, $ss);
					if (isset($detail_list[$s])) {
						$detail_list[$s]['fbm_order_source_id'] = $ss['id'];
						$detail_list[$s]['fbm_order_id'] = $order['id'];
						$detail_list[$s]['id'] = ServiceTwitter::instance()->nextId($order['channel_id'],
							$order['channel_account_id']);
						array_push($newDetail, $detail_list[$s]);
					}
				}
				$warehouse = new WarehouseModel();
				$warehouseId = $warehouse->field('id')->where('name', 'like', '%mymall海外仓')->find();
				$order['warehouse_id'] = $warehouseId['id'] ?? '';
				Db::startTrans();
				try {
					$fbmOrderModel->allowField(true)->save($order);
					$fbmOrderSourceDetailModel->allowField(true)->isUpdate(false)->saveAll($source, false);
					$fbmOrderDetailModel->allowField(true)->isUpdate(false)->saveAll($newDetail, false);
					Db::commit();
					array_push($success, $order['channel_order_id']);
					//统计fbs销售额与订单数
					if (!$warehouseId) {
						continue;
					}
					ReportService::saleByDeeps($order['channel_id'], $order['site'], $order['warehouse_id'], $order['channel_account_id'],
						[
							'sale' => $order['pay_fee'] * $order['to_cny_rate'],
							'delivery' => 1
						]);
					$row = [];
					$warehouseGoods = new WarehouseGoods();
					foreach ($newDetail as $d) {
						$warehouseGoods->waiting_shipping_quantity($order['warehouse_id'], $d['sku_id'], $d['sku_quantity']);
						$skuInfo = [];
						$skuInfo['goods_id'] = $d['goods_id'] ?? '';
						$skuInfo['sku_id'] = $d['sku_id'] ?? '';
						$skuInfo['sku'] = $d['sku'] ?? '';
						$skuInfo['quantity'] = $d['sku_quantity'] ? intval($d['sku_quantity']) : 0;
						$skuInfo['actual_quantity'] = $d['sku_quantity'] ? intval($d['sku_quantity']) : 0;
						$skuInfo['price'] = 0;
						$skuInfo['remark'] = '';
						$row['detail'][] = $skuInfo;
					}
					$stockOut = new StockOut();
					$stockOut->insert($order['warehouse_id'], 21, '', '', 2, $row['detail']);

				} catch (Exception $e) {
					Db::rollback();
					throw new OrderException($e->getMessage() . $e->getFile() . $e->getLine());
				}
			} catch (Exception $e) {
				$message = $order['order_number'] . '---' . $e->getMessage() . $e->getFile() . $e->getLine();
				$this->fbm_log($message, $order);
				array_push($failure, $order['channel_order_id']);
			}

		}
		return ['success' => $success, 'failure' => $failure];
	}


	/**
	 * @desc 渠道sku转换成本地sku
	 * @author wangwei
	 * @date 2018-11-5 10:09:43
	 * @param int $channel_id
	 * @param int $account_id
	 * @param array $channel_data
	 * @example $channel_data = [
	 *        [
	 *            'channel_sku'=>'',//Y 渠道sku
	 *            'sku_quantity'=>'',//N 渠道sku数量
	 *        ],
	 * ];
	 */
	public function channelSkuToLocSku($channel_id, $account_id, $channel_data)
	{
		$order = [
			'create_time' => time(),
			'channel_id' => $channel_id,
			'channel_account_id' => $account_id,
		];
		$detail = [];
		foreach ($channel_data as $v) {
			if (!(isset($v['channel_sku']) && $v['channel_sku'])) {
				continue;
			}
			$detail[] = [
				'channel_sku' => $v['channel_sku'],
				'sku_quantity' => param($v, 'sku_quantity', 1),
				'transaction_id' => '',
				'channel_item_id' => '',
				'channel_sku_title' => '',
				'channel_sku_price' => 1,
				'buyer_selected_logistics' => '',
			];
		}
		$odRe = $this->getOrderDetail($detail, $order);
		return $odRe;
	}


}