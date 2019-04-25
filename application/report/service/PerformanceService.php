<?php
// +----------------------------------------------------------------------
// | 
// +----------------------------------------------------------------------
// | File  : ProfitStatement.php
// +----------------------------------------------------------------------
// | Author: tanbin 
// +----------------------------------------------------------------------
// | Date  : 2017-09-19
// +----------------------------------------------------------------------
// +----------------------------------------------------------------------
namespace app\report\service;

use app\common\model\Order;
use app\common\model\OrderPackage;
use app\common\traits\Export;
use app\index\service\TransferShippingFee;
use erp\AbsServer;
use app\common\model\report\ReportStatisticByDeeps as ReportStatisticByDeepsModel;
use app\index\service\User as UserService;
use app\index\service\ChannelAccount;
use app\index\service\MemberShipService;
use app\order\service\AmazonSettlementReportSummary;
use app\index\service\Department;
use app\common\cache\Cache;
use app\common\service\Common;
use app\common\service\CommonQueuer;
use think\Db;
use think\Exception;
use app\report\model\ReportExportFiles;
use app\report\queue\PerformanceExportQueue;
use think\Loader;
use app\report\validate\FileExportValidate;
use app\common\model\User as UserModel;
use app\common\model\FbaOrder as fbaModel;
use app\common\model\Order as OrderModel;
use app\common\model\Department as DepartmentModel;
use app\index\service\DepartmentUserMapService as DepartmentUserMapService;

Loader::import('phpExcel.PHPExcel', VENDOR_PATH);

class PerformanceService extends AbsServer
{
    use Export;
    private $job_group_leader = 18; //组长
    private $job_director = 17;//主管
    protected $model;
    protected $where = [];
    protected $colMap = [
        'amazon' => [
            'account' => [
                'title' => [
                    'A' => ['title' => '账号简称', 'width' => 20],
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '销售主管', 'width' => 20],
                    'E' => ['title' => '订单数', 'width' => 10],
                    'F' => ['title' => '售价CNY', 'width' => 15],
                    'G' => ['title' => '平台费用CNY', 'width' => 15],
                    'H' => ['title' => '收款费用CNY', 'width' => 15],
                    'I' => ['title' => '物流费用', 'width' => 15],
                    'J' => ['title' => '头程报关费', 'width' => 15],
                    'K' => ['title' => '商品成本', 'width' => 15],
                    'L' => ['title' => '毛利', 'width' => 15],
                    'M' => ['title' => '转运费', 'width' => 10],
                    'N' => ['title' => '退款', 'width' => 10],
                    'O' => ['title' => '店铺费用', 'width' => 10],
                    'P' => ['title' => '广告费用', 'width' => 15],
                    'Q' => ['title' => '实际利润', 'width' => 15],
                    'R' => ['title' => '利润率', 'width' => 15],
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'sale_director' => ['col' => 'D', 'type' => 'str'],
                    'order_num' => ['col' => 'E', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'F', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'G', 'type' => 'numeric'],
                    'p_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'J', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'K', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'L', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'M', 'type' => 'str'],
                    'refund_amount' => ['col' => 'N', 'type' => 'numeric'],
                    'shop_fee' => ['col' => 'O', 'type' => 'numeric'],
                    'ads_fee' => ['col' => 'P', 'type' => 'numeric'],
                    'profit' => ['col' => 'Q', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'R', 'type' => 'numeric'],
                ],
                'last_col' => 'R'
            ],
            'seller' => [
                'title' => [
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '销售主管', 'width' => 20],
                    'E' => ['title' => '订单数', 'width' => 10],
                    'F' => ['title' => '售价CNY', 'width' => 15],
                    'G' => ['title' => '平台费用CNY', 'width' => 15],
                    'H' => ['title' => '收款费用  ', 'width' => 15],
                    'I' => ['title' => '物流费用', 'width' => 15],
                    'J' => ['title' => '头程报关费', 'width' => 15],
                    'K' => ['title' => '商品成本', 'width' => 15],
                    'L' => ['title' => '毛利', 'width' => 15],
                    'M' => ['title' => '转运费', 'width' => 10],
                    'N' => ['title' => '退款', 'width' => 15],
                    'O' => ['title' => '店铺费用', 'width' => 15],
                    'P' => ['title' => '广告费用', 'width' => 10],
                    'Q' => ['title' => '实际利润', 'width' => 10],
                    'R' => ['title' => '利润率', 'width' => 10],
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'sale_director' => ['col' => 'D', 'type' => 'str'],
                    'order_num' => ['col' => 'E', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'F', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'G', 'type' => 'numeric'],
                    'p_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'J', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'K', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'L', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'M', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'N', 'type' => 'numeric'],
                    'shop_fee' => ['col' => 'O', 'type' => 'numeric'],
                    'ads_fee' => ['col' => 'P', 'type' => 'numeric'],
                    'profit' => ['col' => 'Q', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'R', 'type' => 'numeric'],
                ],
                'last_col' => 'S'
            ],
            'overseas' => [
                'title' => [
                    'A' => ['title' => '账号简称', 'width' => 20],
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '销售主管', 'width' => 20],
                    'E' => ['title' => '订单数', 'width' => 10],
                    'F' => ['title' => '售价CNY', 'width' => 15],
                    'G' => ['title' => '平台费用CNY', 'width' => 15],
                    'H' => ['title' => '收款费用CNY', 'width' => 15],
                    'I' => ['title' => '物流费用', 'width' => 15],
                    'J' => ['title' => '头程报关费', 'width' => 15],
                    'K' => ['title' => '商品成本', 'width' => 15],
                    'L' => ['title' => '毛利', 'width' => 15],
                    'M' => ['title' => '转运费', 'width' => 10],
                    'N' => ['title' => '退款', 'width' => 10],
                    'O' => ['title' => '实际利润', 'width' => 10],
                    'P' => ['title' => '利润率', 'width' => 15],
                    'Q' => ['title' => '店铺费用', 'width' => 10],
                    'R' => ['title' => '广告费用', 'width' => 10]
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'sale_director' => ['col' => 'D', 'type' => 'str'],
                    'order_num' => ['col' => 'E', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'F', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'G', 'type' => 'numeric'],
                    'p_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'J', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'K', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'L', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'M', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'N', 'type' => 'numeric'],
                    'profit' => ['col' => 'O', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'P', 'type' => 'numeric'],
                    'shop_fee' => ['col' => 'Q', 'type' => 'str'],
                    'ads_fee' => ['col' => 'R', 'type' => 'numeric'],
                ],
                'last_col' => 'Q'
            ],
            'local' => [
                'title' => [
                    'A' => ['title' => '账号简称', 'width' => 20],
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '销售主管', 'width' => 20],
                    'E' => ['title' => '订单数', 'width' => 10],
                    'F' => ['title' => '售价CNY', 'width' => 10],
                    'G' => ['title' => '平台费用CNY  ', 'width' => 15],
                    'H' => ['title' => '收款费用CNY', 'width' => 15],
                    'I' => ['title' => '物流费用', 'width' => 15],
                    'J' => ['title' => '头程报关费', 'width' => 15],
                    'K' => ['title' => '商品成本', 'width' => 15],
                    'L' => ['title' => '毛利', 'width' => 15],
                    'M' => ['title' => '转运费', 'width' => 10],
                    'N' => ['title' => '退款', 'width' => 10],
                    'O' => ['title' => '店铺费用', 'width' => 10],
                    'P' => ['title' => '广告费用', 'width' => 15],
                    'Q' => ['title' => '实际利润', 'width' => 15],
                    'R' => ['title' => '利润率', 'width' => 15],
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'sale_director' => ['col' => 'D', 'type' => 'str'],
                    'order_num' => ['col' => 'E', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'F', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'G', 'type' => 'numeric'],
                    'p_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'J', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'K', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'L', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'M', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'N', 'type' => 'numeric'],
                    'shop_fee' => ['col' => 'O', 'type' => 'numeric'],
                    'ads_fee' => ['col' => 'P', 'type' => 'numeric'],
                    'profit' => ['col' => 'Q', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'R', 'type' => 'str'],
                ],
                'last_col' => 'R'
            ],
        ],
        'wish' => [
            'account' => [
                'title' => [
                    'A' => ['title' => '账号简称', 'width' => 20],
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '订单数', 'width' => 10],
                    'E' => ['title' => '售价CNY', 'width' => 15],
                    'F' => ['title' => '平台费用CNY', 'width' => 15],
                    'G' => ['title' => 'P卡费用', 'width' => 15],
                    'H' => ['title' => '物流费用', 'width' => 15],
                    'I' => ['title' => '包装费用', 'width' => 15],
                    'J' => ['title' => '头程报关费', 'width' => 15],
                    'K' => ['title' => '商品成本', 'width' => 15],
                    'L' => ['title' => '毛利', 'width' => 10],
                    'M' => ['title' => '转运费', 'width' => 10],
                    'N' => ['title' => '退款', 'width' => 10],
                    'O' => ['title' => '推广费', 'width' => 15],
                    'P' => ['title' => '罚款', 'width' => 10],
                    'Q' => ['title' => '活动现金返利', 'width' => 20],
                    'R' => ['title' => '实际利润', 'width' => 15],
                    'S' => ['title' => '利润率', 'width' => 10],
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'order_num' => ['col' => 'D', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'E', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'F', 'type' => 'numeric'],
                    'p_fee' => ['col' => 'G', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'package_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'J', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'K', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'L', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'M', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'N', 'type' => 'numeric'],
                    'ads_fee' => ['col' => 'O', 'type' => 'numeric'],
                    'fine' => ['col' => 'P', 'type' => 'numeric'],
                    'cash_rebate' => ['col' => 'Q', 'type' => 'numeric'],
                    'profit' => ['col' => 'R', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'S', 'type' => 'str'],
                ],
                'last_col' => 'R'
            ],
            'seller' => [
                'title' => [
                    'A' => ['title' => '销售员', 'width' => 20],
                    'B' => ['title' => '订单数', 'width' => 10],
                    'C' => ['title' => '售价CNY', 'width' => 15],
                    'D' => ['title' => '平台费用CNY', 'width' => 15],
                    'E' => ['title' => 'P卡费用', 'width' => 15],
                    'F' => ['title' => '物流费用', 'width' => 15],
                    'G' => ['title' => '包装费用', 'width' => 15],
                    'H' => ['title' => '头程报关费', 'width' => 15],
                    'I' => ['title' => '商品成本', 'width' => 15],
                    'J' => ['title' => '毛利', 'width' => 10],
                    'K' => ['title' => '转运费', 'width' => 10],
                    'L' => ['title' => '退款', 'width' => 10],
                    'M' => ['title' => '推广费', 'width' => 15],
                    'N' => ['title' => '罚款', 'width' => 10],
                    'O' => ['title' => '活动现金返利', 'width' => 10],
                    'P' => ['title' => '实际利润', 'width' => 10],
                    'Q' => ['title' => '利润率', 'width' => 10],
                ],
                'data' => [
                    'sale_user' => ['col' => 'A', 'type' => 'str'],
                    'order_num' => ['col' => 'B', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'C', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'D', 'type' => 'numeric'],
                    'p_fee' => ['col' => 'E', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'F', 'type' => 'numeric'],
                    'package_fee' => ['col' => 'G', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'I', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'J', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'K', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'L', 'type' => 'numeric'],
                    'ads_fee' => ['col' => 'M', 'type' => 'numeric'],
                    'fine' => ['col' => 'N', 'type' => 'numeric'],
                    'cash_rebate' => ['col' => 'O', 'type' => 'numeric'],
                    'profit' => ['col' => 'P', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'Q', 'type' => 'str'],
                ],
                'last_col' => 'P'
            ],
            'overseas' => [
                'title' => [
                    'A' => ['title' => '账号简称', 'width' => 20],
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '订单数', 'width' => 10],
                    'E' => ['title' => '售价CNY', 'width' => 15],
                    'F' => ['title' => '平台费用CNY', 'width' => 15],
                    'G' => ['title' => 'P卡费用', 'width' => 15],
                    'H' => ['title' => '物流费用', 'width' => 15],
                    'I' => ['title' => '包装费用', 'width' => 15],
                    'J' => ['title' => '头程报关费', 'width' => 15],
                    'K' => ['title' => '商品成本', 'width' => 15],
                    'L' => ['title' => '毛利', 'width' => 10],
                    'M' => ['title' => '转运费', 'width' => 10],
                    'N' => ['title' => '退款', 'width' => 10],
                    'O' => ['title' => '推广费', 'width' => 15],
                    'P' => ['title' => '罚款', 'width' => 10],
                    'Q' => ['title' => '活动现金返利', 'width' => 20],
                    'R' => ['title' => '实际利润', 'width' => 15],
                    'S' => ['title' => '利润率', 'width' => 10],
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'order_num' => ['col' => 'D', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'E', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'F', 'type' => 'numeric'],
                    'p_fee' => ['col' => 'G', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'package_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'J', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'K', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'L', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'M', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'N', 'type' => 'numeric'],
                    'ads_fee' => ['col' => 'O', 'type' => 'numeric'],
                    'fine' => ['col' => 'P', 'type' => 'numeric'],
                    'cash_rebate' => ['col' => 'Q', 'type' => 'numeric'],
                    'profit' => ['col' => 'R', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'S', 'type' => 'str'],
                ],
                'last_col' => 'R'
            ],
            'local' => [
                'title' => [
                    'A' => ['title' => '账号简称', 'width' => 20],
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '订单数', 'width' => 10],
                    'E' => ['title' => '售价CNY', 'width' => 15],
                    'F' => ['title' => '平台费用CNY', 'width' => 15],
                    'G' => ['title' => 'P卡费用', 'width' => 15],
                    'H' => ['title' => '物流费用', 'width' => 15],
                    'I' => ['title' => '包装费用', 'width' => 15],
                    'J' => ['title' => '头程报关费', 'width' => 15],
                    'K' => ['title' => '商品成本', 'width' => 15],
                    'L' => ['title' => '毛利', 'width' => 10],
                    'M' => ['title' => '转运费', 'width' => 10],
                    'N' => ['title' => '退款', 'width' => 10],
                    'O' => ['title' => '推广费', 'width' => 15],
                    'P' => ['title' => '实际利润', 'width' => 15],
                    'Q' => ['title' => '利润率', 'width' => 10],
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'order_num' => ['col' => 'D', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'E', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'F', 'type' => 'numeric'],
                    'p_fee' => ['col' => 'G', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'package_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'J', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'K', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'L', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'M', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'N', 'type' => 'numeric'],
                    'ads_fee' => ['col' => 'O', 'type' => 'numeric'],
                    'profit' => ['col' => 'P', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'Q', 'type' => 'str'],
                ],
                'last_col' => 'R'
            ],
        ],
        'aliExpress' => [
            'account' => [
                'title' => [
                    'A' => ['title' => '账号简称', 'width' => 20],
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '订单数', 'width' => 10],
                    'E' => ['title' => '售价CNY', 'width' => 15],
                    'F' => ['title' => '平台费用CNY', 'width' => 15],
                    'G' => ['title' => '物流费用', 'width' => 15],
                    'H' => ['title' => '包装费用', 'width' => 15],
                    'I' => ['title' => '头程报关费', 'width' => 15],
                    'J' => ['title' => '商品成本', 'width' => 15],
                    'K' => ['title' => '毛利', 'width' => 10],
                    'L' => ['title' => '转运费', 'width' => 10],
                    'M' => ['title' => '退款', 'width' => 10],
                    'N' => ['title' => '账号年费', 'width' => 15],
                    'O' => ['title' => '店铺费用', 'width' => 15],
                    'P' => ['title' => '实际利润', 'width' => 15],
                    'Q' => ['title' => '利润率', 'width' => 10],
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'order_num' => ['col' => 'D', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'E', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'F', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'G', 'type' => 'numeric'],
                    'package_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'J', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'K', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'L', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'M', 'type' => 'numeric'],
                    'account_fee' => ['col' => 'N', 'type' => 'numeric'],
                    'shop_fee' => ['col' => 'O', 'type' => 'numeric'],
                    'profit' => ['col' => 'P', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'Q', 'type' => 'str'],
                ],
                'last_col' => 'P'
            ],
            'seller' => [
                'title' => [
                    'A' => ['title' => '销售员', 'width' => 20],
                    'B' => ['title' => '订单数', 'width' => 10],
                    'C' => ['title' => '售价CNY', 'width' => 15],
                    'D' => ['title' => '平台费用CNY', 'width' => 15],
                    'E' => ['title' => '物流费用', 'width' => 15],
                    'F' => ['title' => '包装费用', 'width' => 15],
                    'G' => ['title' => '头程报关费', 'width' => 15],
                    'H' => ['title' => '商品成本', 'width' => 15],
                    'I' => ['title' => '毛利', 'width' => 10],
                    'J' => ['title' => '转运费', 'width' => 10],
                    'K' => ['title' => '退款', 'width' => 10],
                    'L' => ['title' => '账号年费', 'width' => 15],
                    'M' => ['title' => '店铺费用', 'width' => 15],
                    'N' => ['title' => '实际利润', 'width' => 15],
                    'O' => ['title' => '利润率', 'width' => 10],
                ],
                'data' => [
                    'sale_user' => ['col' => 'A', 'type' => 'str'],
                    'order_num' => ['col' => 'B', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'C', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'D', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'E', 'type' => 'numeric'],
                    'package_fee' => ['col' => 'F', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'G', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'H', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'I', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'J', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'K', 'type' => 'numeric'],
                    'account_fee' => ['col' => 'L', 'type' => 'numeric'],
                    'shop_fee' => ['col' => 'M', 'type' => 'numeric'],
                    'profit' => ['col' => 'N', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'O', 'type' => 'str'],
                ],
                'last_col' => 'N'
            ],
            'overseas' => [
                'title' => [
                    'A' => ['title' => '账号简称', 'width' => 20],
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '订单数', 'width' => 10],
                    'E' => ['title' => '售价CNY', 'width' => 15],
                    'F' => ['title' => '平台费用CNY', 'width' => 15],
                    'G' => ['title' => '物流费用', 'width' => 15],
                    'H' => ['title' => '包装费用', 'width' => 15],
                    'I' => ['title' => '头程报关费', 'width' => 15],
                    'J' => ['title' => '商品成本', 'width' => 15],
                    'K' => ['title' => '毛利', 'width' => 10],
                    'L' => ['title' => '转运费', 'width' => 10],
                    'M' => ['title' => '退款', 'width' => 10],
                    'N' => ['title' => '实际利润', 'width' => 15],
                    'O' => ['title' => '利润率', 'width' => 10],
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'order_num' => ['col' => 'D', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'E', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'F', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'G', 'type' => 'numeric'],
                    'package_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'J', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'K', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'L', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'M', 'type' => 'numeric'],
                    'profit' => ['col' => 'N', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'O', 'type' => 'str'],
                ],
                'last_col' => 'N'
            ],
            'local' => [
                'title' => [
                    'A' => ['title' => '账号简称', 'width' => 20],
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '订单数', 'width' => 10],
                    'E' => ['title' => '售价CNY', 'width' => 15],
                    'F' => ['title' => '平台费用CNY', 'width' => 15],
                    'G' => ['title' => '物流费用', 'width' => 15],
                    'H' => ['title' => '包装费用', 'width' => 15],
                    'I' => ['title' => '头程报关费', 'width' => 15],
                    'J' => ['title' => '商品成本', 'width' => 15],
                    'K' => ['title' => '毛利', 'width' => 10],
                    'L' => ['title' => '转运费', 'width' => 10],
                    'M' => ['title' => '退款', 'width' => 10],
                    'N' => ['title' => '账号年费', 'width' => 15],
                    'O' => ['title' => '店铺费用', 'width' => 15],
                    'P' => ['title' => '实际利润', 'width' => 15],
                    'Q' => ['title' => '利润率', 'width' => 10],
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'order_num' => ['col' => 'D', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'E', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'F', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'G', 'type' => 'numeric'],
                    'package_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'J', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'K', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'L', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'M', 'type' => 'numeric'],
                    'account_fee' => ['col' => 'N', 'type' => 'numeric'],
                    'shop_fee' => ['col' => 'O', 'type' => 'numeric'],
                    'profit' => ['col' => 'P', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'Q', 'type' => 'str'],
                ],
                'last_col' => 'P'
            ],
        ],
        'ebay' => [
            'account' => [
                'title' => [
                    'A' => ['title' => '账号简称', 'width' => 20],
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '销售主管', 'width' => 20],
                    'E' => ['title' => '订单数', 'width' => 10],
                    'F' => ['title' => '售价CNY', 'width' => 15],
                    'G' => ['title' => '平台费用CNY', 'width' => 15],
                    'H' => ['title' => 'PayPal费用', 'width' => 15],
                    'I' => ['title' => '货币转换费', 'width' => 15],
                    'J' => ['title' => '物流费用', 'width' => 15],
                    'K' => ['title' => '包装费用', 'width' => 15],
                    'L' => ['title' => '头程报关费', 'width' => 15],
                    'M' => ['title' => '商品成本', 'width' => 15],
                    'N' => ['title' => '毛利', 'width' => 10],
                    'O' => ['title' => '转运费', 'width' => 10],
                    'P' => ['title' => '退款', 'width' => 10],
                    'Q' => ['title' => '店铺费用', 'width' => 15],
                    'R' => ['title' => '实际利润', 'width' => 15],
                    'S' => ['title' => '利润率', 'width' => 10],
                    'T' => ['title' => '呆货成本补贴', 'width' => 15],
                    'U' => ['title' => '补贴后利润', 'width' => 15],
                    'V' => ['title' => '补贴后利润率', 'width' => 15],
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'sale_director' => ['col' => 'D', 'type' => 'str'],
                    'order_num' => ['col' => 'E', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'F', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'G', 'type' => 'numeric'],
                    'paypal_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'currency_transform_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'J', 'type' => 'numeric'],
                    'package_fee' => ['col' => 'K', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'L', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'M', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'N', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'O', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'P', 'type' => 'numeric'],
                    'shop_fee' => ['col' => 'Q', 'type' => 'numeric'],
                    'profit' => ['col' => 'R', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'S', 'type' => 'str'],
                    'cost_subsidy' => ['col' => 'T', 'type' => 'numeric'],
                    'after_subsidy_profits' => ['col' => 'U', 'type' => 'numeric'],
                    'after_subsidy_profits_rate' => ['col' => 'V', 'type' => 'numeric'],
                ],
                'last_col' => 'U'
            ],
            'seller' => [
                'title' => [
                    'A' => ['title' => '销售员', 'width' => 20],
                    'B' => ['title' => '订单数', 'width' => 10],
                    'C' => ['title' => '售价CNY', 'width' => 15],
                    'D' => ['title' => '平台费用CNY', 'width' => 15],
                    'E' => ['title' => 'PayPal费用', 'width' => 15],
                    'F' => ['title' => '货币转换费', 'width' => 15],
                    'G' => ['title' => '物流费用', 'width' => 15],
                    'H' => ['title' => '包装费用', 'width' => 15],
                    'I' => ['title' => '头程报关费', 'width' => 15],
                    'J' => ['title' => '商品成本', 'width' => 15],
                    'K' => ['title' => '毛利', 'width' => 10],
                    'L' => ['title' => '转运费', 'width' => 10],
                    'M' => ['title' => '退款', 'width' => 10],
                    'N' => ['title' => '店铺费用', 'width' => 15],
                    'O' => ['title' => '实际利润', 'width' => 15],
                    'P' => ['title' => '利润率', 'width' => 10],
                    'Q' => ['title' => '呆货成本补贴', 'width' => 15],
                    'R' => ['title' => '补贴后利润', 'width' => 15],
                    'S' => ['title' => '补贴后利润率', 'width' => 15],
                ],
                'data' => [
                    'sale_user' => ['col' => 'A', 'type' => 'str'],
                    'order_num' => ['col' => 'B', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'C', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'D', 'type' => 'numeric'],
                    'paypal_fee' => ['col' => 'E', 'type' => 'numeric'],
                    'currency_transform_fee' => ['col' => 'F', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'G', 'type' => 'numeric'],
                    'package_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'J', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'K', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'L', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'M', 'type' => 'numeric'],
                    'shop_fee' => ['col' => 'N', 'type' => 'numeric'],
                    'profit' => ['col' => 'O', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'P', 'type' => 'str'],
                    'cost_subsidy' => ['col' => 'Q', 'type' => 'numeric'],
                    'after_subsidy_profits' => ['col' => 'R', 'type' => 'numeric'],
                    'after_subsidy_profits_rate' => ['col' => 'S', 'type' => 'str'],
                ],
                'last_col' => 'R'
            ],
            'overseas' => [
                'title' => [
                    'A' => ['title' => '账号简称', 'width' => 20],
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '销售主管', 'width' => 20],
                    'E' => ['title' => '订单数', 'width' => 10],
                    'F' => ['title' => '售价CNY', 'width' => 15],
                    'G' => ['title' => '平台费用CNY', 'width' => 15],
                    'H' => ['title' => 'PayPal费用', 'width' => 15],
                    'I' => ['title' => '货币转换费', 'width' => 15],
                    'J' => ['title' => '物流费用', 'width' => 15],
                    'K' => ['title' => '包装费用', 'width' => 15],
                    'L' => ['title' => '头程报关费', 'width' => 15],
                    'M' => ['title' => '商品成本', 'width' => 15],
                    'N' => ['title' => '毛利', 'width' => 10],
                    'O' => ['title' => '转运费', 'width' => 10],
                    'P' => ['title' => '退款', 'width' => 10],
                    'Q' => ['title' => '店铺费用', 'width' => 15],
                    'R' => ['title' => '实际利润', 'width' => 15],
                    'S' => ['title' => '利润率', 'width' => 10],
                    'T' => ['title' => '呆货成本补贴', 'width' => 15],
                    'U' => ['title' => '补贴后利润', 'width' => 15],
                    'V' => ['title' => '补贴后利润率', 'width' => 15],
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'sale_director' => ['col' => 'D', 'type' => 'str'],
                    'order_num' => ['col' => 'E', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'F', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'G', 'type' => 'numeric'],
                    'paypal_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'currency_transform_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'J', 'type' => 'numeric'],
                    'package_fee' => ['col' => 'K', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'L', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'M', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'N', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'O', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'P', 'type' => 'numeric'],
                    'shop_fee' => ['col' => 'Q', 'type' => 'numeric'],
                    'profit' => ['col' => 'R', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'S', 'type' => 'str'],
                    'cost_subsidy' => ['col' => 'T', 'type' => 'numeric'],
                    'after_subsidy_profits' => ['col' => 'U', 'type' => 'numeric'],
                    'after_subsidy_profits_rate' => ['col' => 'V', 'type' => 'numeric'],
                ],
                'last_col' => 'U'
            ],
            'local ' => [
                'title' => [
                    'A' => ['title' => '账号简称', 'width' => 20],
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '销售主管', 'width' => 15],
                    'E' => ['title' => '订单数', 'width' => 10],
                    'F' => ['title' => '售价CNY', 'width' => 15],
                    'G' => ['title' => '平台费用CNY', 'width' => 15],
                    'H' => ['title' => 'PayPal费用', 'width' => 15],
                    'I' => ['title' => '货币转换费', 'width' => 15],
                    'J' => ['title' => '物流费用', 'width' => 15],
                    'K' => ['title' => '包装费用', 'width' => 15],
                    'L' => ['title' => '头程报关费', 'width' => 15],
                    'M' => ['title' => '商品成本', 'width' => 15],
                    'N' => ['title' => '毛利', 'width' => 10],
                    'O' => ['title' => '转运费', 'width' => 10],
                    'P' => ['title' => '退款', 'width' => 10],
                    'Q' => ['title' => '店铺费用', 'width' => 15],
                    'R' => ['title' => '实际利润', 'width' => 15],
                    'S' => ['title' => '利润率', 'width' => 10],
                    'T' => ['title' => '呆货成本补贴', 'width' => 15],
                    'U' => ['title' => '补贴后利润', 'width' => 15],
                    'V' => ['title' => '补贴后利润率', 'width' => 15],
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'sale_director' => ['col' => 'D', 'type' => 'str'],
                    'order_num' => ['col' => 'E', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'F', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'G', 'type' => 'numeric'],
                    'paypal_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'currency_transform_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'J', 'type' => 'numeric'],
                    'package_fee' => ['col' => 'K', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'L', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'M', 'type' => 'numeric'],
                    'gross_profit' => ['col' => 'N', 'type' => 'numeric'],
                    'trans_shipping_fee' => ['col' => 'O', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'P', 'type' => 'numeric'],
                    'shop_fee' => ['col' => 'Q', 'type' => 'numeric'],
                    'profit' => ['col' => 'R', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'S', 'type' => 'str'],
                    'cost_subsidy' => ['col' => 'T', 'type' => 'numeric'],
                    'after_subsidy_profits' => ['col' => 'U', 'type' => 'numeric'],
                    'after_subsidy_profits_rate' => ['col' => 'V', 'type' => 'numeric'],
                ],
                'last_col' => 'U'
            ],
        ],
        'fba' => [
            'account' => [
                'title' => [
                    'A' => ['title' => '账号简称', 'width' => 20],
                    'B' => ['title' => '销售员', 'width' => 20],
                    'C' => ['title' => '销售组长', 'width' => 20],
                    'D' => ['title' => '销售主管', 'width' => 20],
                    'E' => ['title' => '订单数', 'width' => 10],
                    'F' => ['title' => '售价CNY', 'width' => 15],
                    'G' => ['title' => '平台费用CNY', 'width' => 15],
                    'H' => ['title' => '物流费用', 'width' => 15],
                    'I' => ['title' => '头程报关费', 'width' => 15],
                    'J' => ['title' => '商品成本', 'width' => 15],
                    'K' => ['title' => '退款', 'width' => 10],
                    'L' => ['title' => '店铺费用', 'width' => 15],
                    'M' => ['title' => '实际利润', 'width' => 10],
                    'N' => ['title' => '利润率', 'width' => 10],
                    'O' => ['title' => '毛利', 'width' => 10],
                    'P' => ['title' => '调整费用', 'width' => 10],
                    'Q' => ['title' => '广告费用', 'width' => 10],
                ],
                'data' => [
                    'account_code' => ['col' => 'A', 'type' => 'str'],
                    'sale_user' => ['col' => 'B', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'C', 'type' => 'str'],
                    'sale_director' => ['col' => 'D', 'type' => 'str'],
                    'order_num' => ['col' => 'E', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'F', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'G', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'I', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'J', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'K', 'type' => 'numeric'],
                    'shop_fee' => ['col' => 'L', 'type' => 'numeric'],
                    'profit' => ['col' => 'M', 'type' => 'numeric'],//实际利润
                    'profit_rate' => ['col' => 'N', 'type' => 'str'],
                    'gross_profit' => ['col' => 'O', 'type' => 'str'],
                    'adjust_fee' => ['col' => 'P', 'type' => 'str'],
                    'ads_fee' => ['col' => 'Q', 'type' => 'str'],
                ],
                'last_col' => 'Q'
            ],

            'seller ' => [
                'title' => [
                    'A' => ['title' => '销售员', 'width' => 20],
                    'B' => ['title' => '销售组长', 'width' => 20],
                    'C' => ['title' => '销售主管', 'width' => 20],
                    'D' => ['title' => '订单数', 'width' => 10],
                    'E' => ['title' => '售价CNY', 'width' => 15],
                    'F' => ['title' => '平台费用CNY', 'width' => 15],
                    'G' => ['title' => '物流费用', 'width' => 15],
                    'H' => ['title' => '头程报关费', 'width' => 15],
                    'I' => ['title' => '商品成本', 'width' => 15],
                    'J' => ['title' => '退款', 'width' => 10],
                    'K' => ['title' => '店铺费用', 'width' => 15],
                    'L' => ['title' => '利润', 'width' => 10],
                    'M' => ['title' => '利润率', 'width' => 10]
                ],
                'data' => [
                    'sale_user' => ['col' => 'A', 'type' => 'str'],
                    'sale_group_leader' => ['col' => 'B', 'type' => 'str'],
                    'sale_director' => ['col' => 'C', 'type' => 'str'],
                    'order_num' => ['col' => 'D', 'type' => 'numeric'],
                    'sale_amount' => ['col' => 'E', 'type' => 'numeric'],
                    'channel_cost' => ['col' => 'F', 'type' => 'numeric'],
                    'shipping_fee' => ['col' => 'G', 'type' => 'numeric'],
                    'first_fee' => ['col' => 'H', 'type' => 'numeric'],
                    'goods_cost' => ['col' => 'I', 'type' => 'numeric'],
                    'refund_amount' => ['col' => 'J', 'type' => 'numeric'],
                    'shop_fee' => ['col' => 'K', 'type' => 'numeric'],
                    'profit' => ['col' => 'L', 'type' => 'numeric'],
                    'profit_rate' => ['col' => 'M', 'type' => 'str'],
                ],
                'last_col' => 'M'
            ],
        ]
    ];

    protected function init()
    {
        if (is_null($this->model)) {
            $this->model = new ReportStatisticByDeepsModel();
        }
    }

    /**
     * 搜索参数
     * @param array $params
     * @return array
     */
    public function getWhere(array $params)
    {
        $where = [];
        switch ($params['report_type']) {
            case 'local':
                $where['r.warehouse_type'] = ['eq', 1];//本地仓
                break;
            case 'overseas':
                $where['r.warehouse_type'] = ['eq', 3];//海外仓
                break;
            default:
                break;
        }
        #平台id搜索
        if (param($params, 'channel_id')) {
            $where['r.channel_id'] = ['eq', $params['channel_id']];
        } else {
            //fba
            $where['r.warehouse_type'] = 5;
        }

        #账号id搜索
        if (param($params, 'account_id')) {
            $where['r.account_id'] = ['eq', $params['account_id']];
        }

        #销售员搜索(要修改)
        if (param($params, 'saler_id')) {
            $where['r.user_id'] = ['eq', $params['saler_id']];
        }

        #按照发货日期 搜索
        if (param($params, 'search_time') && in_array($params['search_time'], ['shipping_time'])) {
            //switch ($params['search_time']){
            //    case 'shipping_time':
            //       $search_time = 'dateline';
            //        break;
            //    default:
            //        break;
            //}

            $b_time = !empty(param($params, 'date_b')) ? strtotime($params['date_b'] . ' 00:00:00') : '';
            $e_time = !empty(param($params, 'date_e')) ? strtotime($params['date_e'] . ' 23:59:59') : '';

            if ($b_time && $e_time) {
                $where['r.dateline'] = ['BETWEEN', [$b_time, $e_time]];
                //$where['o.shipping_time']  =  ['BETWEEN', [$b_time, $e_time]];
            } elseif ($b_time) {
                $where['r.dateline'] = ['EGT', $b_time];
                //$where['o.shipping_time']  = ['EGT',$b_time];
            } elseif ($e_time) {
                $where['r.dateline'] = ['ELT', $e_time];
                //$where['o.shipping_time']  = ['ELT',$e_time];
            }

        }
        return $where;
    }

    /**
     * 搜索参数
     * @param array $params
     * @return array
     */
    public function where(array $params)
    {
        $where = [];
        #平台id搜索
        if (isset($params['channel_id']) && !empty($params['channel_id'])) {
            $where['channel_id'] = ['eq', $params['channel_id']];
        }
        #账号id搜索
        if (isset($params['account_id']) && !empty($params['account_id'])) {
            $where['channel_account_id'] = ['eq', $params['account_id']];
        }
        #销售员搜索(要修改)
        if (isset($params['saler_id']) && !empty($params['saler_id'])) {
            $where['seller_id'] = ['eq', $params['saler_id']];
        }
        #按照发货日期 搜索
        $b_time = !empty(param($params, 'date_b')) ? $params['date_b'] : '';
        $e_time = !empty(param($params, 'date_e')) ? $params['date_e'] : '';
        $condition = timeCondition($b_time, $e_time);
        if (!is_array($condition)) {
            return json(['message' => '日期格式错误'], 400);
        }
        if (!empty($condition)) {
            $where['pay_time'] = $condition;
        }
        return $where;
    }

    /**
     * @param string
     * @return array
     */
    /*public function getJoin($report_type)
    {
        if($report_type=='seller'){
            //销售员利润汇总
            $join[] = ['order o', 'o.seller_id = r.user_id', 'left'];
        }else{
            //销售账号利润汇总（包括海外、本地）
            $join[] = ['order o', 'o.channel_account_id = r.account_id', 'left'];
        }
        return $join;
    }*/

    /**
     * @param string
     * @return string
     */
    public function getGroupBy($report_type)
    {
        if ($report_type == 'seller') {
            //销售员利润汇总
            $group_by = "r.user_id";
        } else {
            //销售账号利润汇总（包括海外、本地）
            $group_by = 'r.account_id';
        }
        return $group_by;
    }

    /**
     * @param string
     * @return string
     */
    public function fbaGroup($report_type)
    {
        if ($report_type == 'seller') {
            //销售员利润汇总
            $group_by = "seller_id";
        } else {
            //销售账号利润汇总（包括海外、本地）
            $group_by = 'channel_account_id';
        }
        return $group_by;
    }

    /**
     * 查询销售账号利润报表
     * @param array $params
     * @return array
     */
    public function search($params)
    {
        $page = param($params, 'page', 1);
        $pageSize = param($params, 'pageSize', 20);

        $lists = $this->assemblyData($this->doSearch($params, $page, $pageSize), $params['report_type'], $params);
        $count = $this->searchCount($params);
        $result = [
            'data' => $lists,
            'page' => $page,
            'pageSize' => $pageSize,
            'count' => $count,
        ];

        return $result;
    }

    /**
     * amazon查询销售账号利润报表
     * @param array $params
     * @return array
     */
    public function amazonSearch($params)
    {
        $page = param($params, 'page', 1);
        $pageSize = param($params, 'pageSize', 20);
        $lists = $this->newData($this->doSearch($params, $page, $pageSize), $params);
        $count = $this->searchCount($params);
        $result = [
            'data' => $lists,
            'page' => $page,
            'pageSize' => $pageSize,
            'count' => $count,
        ];

        return $result;
    }

    /**
     * 查询fba销售账号利润报表
     * @Param array $params
     * @return array
     */
    public function fbaSearch($params)
    {
        $page = param($params, 'page', 1);
        $pageSize = param($params, 'pageSize', 20);

        $lists = $this->newData($this->fbaDoSearch($params, $page, $pageSize), $params);
        $count = $this->fbaCount($params);
        $result = [
            'data' => $lists,
            'page' => $page,
            'pageSize' => $pageSize,
            'count' => $count,
        ];

        return $result;

    }

    /**
     * @param array $params
     * @param int $page
     * @param int $pageSize
     * @return false|\PDOStatement|string|\think\Collection
     */
    protected function doSearch($params, $page = 1, $pageSize = 10)
    {
        $lists = ReportStatisticByDeepsModel::alias('r')
//            ->join($this->getJoin($params['report_type']))
            ->field(
                'r.account_id, ' .    //账号
                'r.channel_id,' .     //渠道
                'r.department_id,' .  //部门
                'sum(r.paypal_fee) as paypal_fee,' .   //paypal费用
                'sum(r.channel_cost) as channel_cost,' . //平台费用
                'sum(r.shipping_fee) as shipping_fee,' . //运费（物流费）
                'sum(r.package_fee) as package_fee,' .   //包装费用
                'sum(r.first_fee) as first_fee,' .       //头程费
                'sum(r.refund_amount) as refund_amount,' . //退款金额
                'sum(r.delivery_quantity) as order_num,' .  //订单发货数
                'sum(r.p_fee) as p_fee,' .    //P卡费用 CNY
                'sum(r.profits) as profits,' .    //利润
                'sum(r.sale_amount) as sale_amount,' .   //售价CNY
                'sum(r.channel_cost) as channel_cost,' .  //渠道成交费CNY
                'sum(r.cost) as goods_cost,' .  //成本
                'r.user_id as seller_id'             //销售员id
            )
            ->where($this->getWhere($params))
            ->group($this->getGroupBy($params['report_type']))
            ->page($page, $pageSize)
            ->select();

        return $lists;
    }

    /**
     * @param array $params
     * @param int $page
     * @param int $pageSize
     * @return false|\PDOStatement|string|\think\Collection
     */
    protected function fbaDoSearch($params, $page = 1, $pageSize = 10)
    {
        $i = 1;
        $field = ['channel_account_id as account_id', 'channel_id', 'sum(pay_fee * rate) as sale_amount', 'sum(channel_cost) as channel_cost', 'sum(channel_shipping_free) as shipping_fee',
            'sum(first_fee) as first_fee', 'count(id) as order_num', 'sum(cost) as goods_cost', 'seller_id'];
        $lists = (new fbaModel())
            ->field($field)
            ->where($this->where($params))
            ->group($this->fbaGroup($params['report_type']))
            ->page($page, $pageSize)
            ->select();
        $i++;
        return $lists;
    }

    /**
     * @param array $params
     * @return int|string
     */
    public function fbaCount($params)
    {
        $count = (new fbaModel())
            ->where($this->where($params))
            ->group($this->fbaGroup($params['report_type']))
            ->count();
        return $count;
    }

    /**
     * @param array $params
     * @return int|string
     */
    public function searchCount($params)
    {
        $count = ReportStatisticByDeepsModel::alias('r')
            ->where($this->getWhere($params))
            ->group($this->getGroupBy($params['report_type']))
            ->count();
        return $count;
    }

    /**
     * 获取销售员组长以及主管
     * @param $userId
     * @return array
     * @throws \think\exception\DbException
     */
    public function getLeaderDirector($userId)
    {
        $data = [];
        $director_id = [];
        $leader_id = [];
        $leader = [];
        $director = [];
        $userInfo = Cache::store('user')->getOneUser($userId);
        $user = new UserService();
        $job = $user->getUserPositionByLoginUser($userId);
        $job['word_id'] = array_diff($job['word_id'], [0]);
        sort($job['word_id']);
        $word_id = $job['word_id'] ? reset($job['word_id']) : 0;
        if ($word_id == $this->job_director) {//销售员本身是个主管
            $leader_id[] = $userId;
            $director_id[] = $userId;
        } elseif ($word_id > $this->job_director) { //主管以下级别
            if ($word_id == $this->job_group_leader) { //本身是销售组长
                $leader_id[] = $userId;
            }
            $departmentUserMapService = new DepartmentUserMapService();
            $department_ids = $departmentUserMapService->getDepartmentByUserId($userId);
            foreach ($department_ids as $d => $department) {
                if (!empty($department)) {
                    if ($word_id != $this->job_group_leader) { //本身不是销售组长
                        $_leader_id = $departmentUserMapService->getGroupLeaderByChannel($department);
                        if (!empty($_leader_id)) {
                            foreach ($_leader_id as $id) {
                                array_push($leader_id, $id);
                            }
                        }
                    }
                    $_director_id = $departmentUserMapService->getDirector($department);
                    if (!empty($_director_id)) {
                        foreach ($_director_id as $id) {
                            array_push($director_id, $id);
                        }
                    }
                }
            }
        }
        foreach ($leader_id as $v) {
            $userInfo = Cache::store('user')->getOneUser($v);
            //$realname = cache::store('user')->getOneUserRealname($v);
            //过滤已经离职的
            if (param($userInfo, 'on_job')) {
                array_push($leader, param($userInfo, 'realname'));
            }
        }
        foreach ($director_id as $v) {
            //过滤已经离职的
            $userInfo = Cache::store('user')->getOneUser($v);
            //$realname = cache::store('user')->getOneUserRealname($v);
            if (param($userInfo, 'on_job')) {
                array_push($director, param($userInfo, 'realname'));
            }
        }
        $data['sale_group_leader'] = !empty($leader) ? implode(', ', $leader) : '';
        $data['sale_director'] = !empty($director) ? implode(', ', $director) : '';
        return $data;
    }

    /**
     * 组装查询返回数据
     * @param array $records
     * @param string $report_type
     * @param array $params
     * @return array
     */
    protected function assemblyData($records, $report_type, $params = [])
    {
        //$UserService = new UserService();
        $ChannelAccountService = new ChannelAccount();
        $MembershipService = new MemberShipService();
        //$DepartmentService = new Department();
        foreach ($records as &$vo) {
            $vo = $vo->toArray();
            if ($report_type == 'seller') {
                //销售员利润汇总
                //$where['seller_id'] = $vo['seller_id'];
                //$where['channel_id'] = $vo['channel_id'];

                //获取销售员
                $sale_user = Cache::store('user')->getOneUser($vo['seller_id']);
                $vo['sale_user'] = $sale_user['realname'] ?? '';
            } else {
                $seller_id = $vo['seller_id'];
                //销售账号利润汇总（包括海外、本地）
                //$where['channel_account_id'] = $vo['account_id'];
                //$where['channel_id'] = $vo['channel_id'];

                //获取卖家账号
                $account = $ChannelAccountService->getAccount($vo['channel_id'], $vo['account_id']);
                $vo['account_code'] = param($account, 'code');
                //获取销售员
                $member = $MembershipService->member($vo['channel_id'], $vo['account_id'], 'sales');
                $has_seller = 0;
                $sales = [];
                if ($member) {
                    foreach ($member as $mvo) {
                        if ($mvo['seller_id'] == $seller_id) {
                            $has_seller = 1;
                        }
                        $sales[] = param($mvo, 'realname');
                        //获取销售员
                        if (isset($mvo['realname'])) {
                            $user_id = (new UserModel())->field('id')->where(['realname' => $mvo['realname']])->find();
                            if (!empty($user_id)) {
                                $vo['seller_id'] = $user_id['id'];
                            }
                        }

                    }
                }
                if ($has_seller == 0) {
                    $sales[] = Cache::store('user')->getOneUser($seller_id)['realname'] ?? '';
                }
                $vo['sale_user'] = $sales ? implode(',', $sales) : '';
            }
            //$vo['goods_cost'] = $orderModel->where($where)->value('sum(cost)', 0);
            //获取账号分组信息
            //$department = $DepartmentService->getDepartment($vo['department_id']);
            //$vo['sale_group_leader'] = param($department, 'leader_id'); //销售组长(要修改)
            //获取销售主管
            //$vo['sale_director'] = "";
            //if(param($department, 'pid')){
            //    $user = $UserService->getUser($department['pid']);
            //    $vo['sale_director'] = param($user, 'realname');
            //}
            $vo['sale_group_leader'] = '';
            $vo['sale_director'] = '';
            $user = $this->getLeaderDirector($vo['seller_id']);
            if (!empty($user)) {
                $vo['sale_group_leader'] = $user['sale_group_leader'];
                $vo['sale_director'] = $user['sale_director'];
            }
            $vo['paypal_fee'] = sprintf('%.2f', $vo['paypal_fee']);//paypal费用
            $vo['channel_cost'] = sprintf('%.2f', $vo['channel_cost']);//平台费用
            $vo['shipping_fee'] = sprintf('%.2f', $vo['shipping_fee']);//运费（物流费）
            $vo['package_fee'] = sprintf('%.2f', $vo['package_fee']);//包装费用
            $vo['first_fee'] = sprintf('%.2f', $vo['first_fee']);//头程费
            $vo['refund_amount'] = sprintf('%.2f', $vo['refund_amount']);//退款金额
            $vo['p_fee'] = sprintf('%.2f', $vo['p_fee']);//P卡费用 CNY
            $vo['profits'] = sprintf('%.2f', $vo['profits']);//利润
            $vo['goods_cost'] = sprintf('%.2f', $vo['goods_cost']);//商品成本
            $vo['sale_amount'] = sprintf('%.2f', $vo['sale_amount']);//售价CNY
            $vo['channel_cost'] = sprintf('%.2f', $vo['channel_cost']);//渠道成交费CNY

            $vo['appraisal_fee'] = 0; //测评费用（没给）
            $vo['ads_fee'] = 0; //广告费用（没给）
            $vo['shop_fee'] = 0; //店铺费用（没给）
            $vo['account_fee'] = 0; //账号年费（没给）
            $vo['fine'] = 0; //罚款（没给）
            $vo['cash_rebate'] = 0; //活动现金返利（没给）
            $vo['cost_subsidy'] = 0; //呆货成本补贴（没给）
            $vo['after_subsidy_profits'] = 0; //补贴后利润（没给）
            $vo['after_subsidy_profits_rate'] = 0; //补贴后利润率（没给）

            //实际售价(售价+测评费用) ？
            $vo['actual_fee'] = $vo['sale_amount'] + $vo['appraisal_fee'];

            //毛利(利润-成本)
            $vo['gross_profit'] = $vo['profits'] - $vo['goods_cost'];

            //转运费
            $vo['trans_shipping_fee'] = $this->getTransShippingFee($vo['channel_id'], $vo['account_id'], $params);

            //实际利润(毛利-店铺费用-广告费用-退款) ？
            $vo['profit'] = $vo['gross_profit'] - $vo['shop_fee'] - $vo['ads_fee'] - $vo['refund_amount'];

            //利润率（实际利润÷实际售价）？
            $vo['profit_rate'] = $vo['actual_fee'] != 0 ? sprintf('%.2f', $vo['profit'] / $vo['actual_fee'] * 100) . '%' : '0.00%';

            //货币转换率（总售价-渠道成交费-PayPal费用）×0.025 ？
            $vo['currency_transform_fee'] = sprintf('%.2f', ($vo['sale_amount'] - $vo['channel_cost'] - $vo['paypal_fee']) * 0.025);
        }
        return $records;
    }

    /**
     * 获取参数
     * @param array $params
     * @param $key
     * @param $default
     * @return mixed
     */
    public function getParameter(array $params, $key, $default)
    {
        $v = $default;
        if (isset($params[$key]) && $params[$key]) {
            $v = $params[$key];
        }
        return $v;
    }

    /**
     * 组装查询返回数据
     * @param array $records
     * @param array $params
     * $param array $title
     * @return array
     */
    protected function newData($records, $params, $title = [])
    {
        set_time_limit(0);
        $info = [];
        $ChannelAccountService = new ChannelAccount();
        $MembershipService = new MemberShipService();
        $b_time = !empty(param($params, 'date_b')) ? strtotime($params['date_b'] . ' 00:00:00') : 0;
        $e_time = !empty(param($params, 'date_e')) ? strtotime($params['date_e'] . ' 23:59:59') : 0;
        foreach ($records as $k => &$vo) {
            $vo = $vo->toArray();
            $data = (new AmazonSettlementReportSummary())->getSettleDataByShippingTime([$vo['account_id']], $b_time, $e_time);
            $data = array_values($data);
            if ($params['channel_id'] == 2) {
                if (param($data[0]['refund_detail'], '2')) {
                    $vo['refund_amount'] = sprintf('%.2f', $data[0]['refund_detail'][2]['refund_amount']);//退款金额(调整）
                } else {
                    $vo['refund_amount'] = 0;
                }
                $fba_amount = $vo['sale_amount'];
                $vo['ads_fee'] = sprintf('%.2f', $vo['sale_amount'] / ($vo['sale_amount'] + $fba_amount) * $data[0]['merchant_fee']);
                $vo['shop_fee'] = sprintf('%.2f', $vo['sale_amount'] / ($vo['sale_amount'] + $fba_amount) * $data[0]['advertise_fee']);
            } else {
                if (param($data[0]['refund_detail'], '1')) {
                    $vo['refund_amount'] = sprintf('%.2f', $data[0]['refund_detail'][1]['refund_amount']);//退款金额(调整）
                } else {
                    $vo['refund_amount'] = 0;
                }
                $order_amount = $vo['sale_amount'];
                $temp = ($vo['sale_amount'] + $order_amount) * $data[0]['merchant_fee'];
                if (!empty($temp)) {
                    $ads_fee = $vo['sale_amount'] / $temp;
                } else {
                    $ads_fee = 0;
                }
                $item = ($vo['sale_amount'] + $order_amount) * $data[0]['advertise_fee'];
                if ($item) {
                    $shop_fee = $vo['sale_amount'] / $item;
                } else {
                    $shop_fee = 0;
                }
                $vo['ads_fee'] = sprintf('%.2f', $ads_fee);
                $vo['shop_fee'] = sprintf('%.2f', $shop_fee);
            }
            if ($params['report_type'] == 'seller') {
                //获取销售员
                $sale_user = Cache::store('user')->getOneUser($vo['seller_id']);
                $vo['sale_user'] = $sale_user['realname'] ?? '';
            } else {
                $seller_id = $vo['seller_id'];
                //获取卖家账号
                $account = $ChannelAccountService->getAccount($vo['channel_id'], $vo['account_id']);
                $vo['account_code'] = param($account, 'code');
                //获取销售员
                $member = $MembershipService->member($vo['channel_id'], $vo['account_id'], 'sales');
                $has_seller = 0;
                $sales = [];
                if ($member) {
                    foreach ($member as $mvo) {
                        if ($mvo['seller_id'] == $seller_id) {
                            $has_seller = 1;
                        }
                        $sales[] = param($mvo, 'realname');
                        //获取销售员
                        if (isset($mvo['realname'])) {
                            $user_id = (new UserModel())->field('id')->where(['realname' => $mvo['realname']])->find();
                            if (!empty($user_id)) {
                                $vo['seller_id'] = $user_id['id'];
                            }
                        }
                    }
                }
                if ($has_seller == 0) {
                    $sales[] = Cache::store('user')->getOneUser($seller_id)['realname'] ?? '';
                }
                $vo['sale_user'] = $sales ? implode(',', $sales) : '';
            }
            $vo['sale_group_leader'] = '';
            $vo['sale_director'] = '';
            $user = $this->getLeaderDirector($vo['seller_id']);
            if (!empty($user)) {
                $vo['sale_group_leader'] = $user['sale_group_leader'];
                $vo['sale_director'] = $user['sale_director'];
            }
            $vo['adjust_fee'] = $data[0]['fba_inventory_amount'] ?? 0;//调整费用
            $vo['sale_amount'] = sprintf('%.2f', $vo['sale_amount']);//售价CNY
            $vo['channel_cost'] = sprintf('%.2f', $vo['channel_cost']);//平台费用
            $vo['shipping_fee'] = sprintf('%.2f', $vo['shipping_fee']);//运费（物流费）
            $vo['first_fee'] = sprintf('%.2f', $vo['first_fee']);//头程费
            $vo['p_fee'] = sprintf('%0.2f', (($vo['sale_amount'] - $vo['channel_cost']) * 0.006));//P卡费用,收款费用CNY=（实际售价- 平台费用CNY）×0.6%
            $vo['goods_cost'] = sprintf('%.2f', $vo['goods_cost']);//商品成本
            //毛利(售价CNY-平台运费CNY-FBA/物流费用-头程报关费-商品成本
            $gross_profit = ($vo['sale_amount'] - $vo['channel_cost'] - $vo['p_fee'] - $vo['shipping_fee'] - $vo['first_fee'] - $vo['goods_cost']);
            $vo['gross_profit'] = sprintf('%0.2f', $gross_profit);
            $vo['trans_shipping_fee'] = $this->getTransShippingFee($vo['channel_id'], $vo['account_id'], $params);
            //实际利润(毛利+退款+店铺费用+广告费用+转运费)调整 ？
            $vo['profit'] = sprintf('%0.2f', ($vo['gross_profit'] + $vo['refund_amount'] + $vo['shop_fee'] + $vo['ads_fee'] + $vo['trans_shipping_fee']));
            //利润率（实际利润÷售价CNY）？
            if ($vo['sale_amount'] == 0) {
                $vo['profit_rate'] = '0.00%';
            } else {
                $vo['profit_rate'] = sprintf('%.2f', $vo['profit'] / $vo['sale_amount'] * 100) . '%';
            }
            unset($vo['paypal_fee']);
            unset($vo['package_fee']);
            unset($vo['account_id']);
            unset($vo['channel_id']);
            unset($vo['seller_id']);
            $pp = [];
            if (empty($title)) {
                $pp = $vo;
            } else {
                foreach ($title as $value) {
                    $pp[$value] = $vo[$value];
                }

            }
            array_push($info, $pp);
        }
        return $info;
    }

    /**
     * 创建导出文件名
     * @param int $channel_id
     * @return string $report_type
     * @return int $user_id
     * @throws Exception
     */
    protected function createExportFileName($params, $channel_id, $report_type, $user_id)
    {
        $ChannelAccountService = new ChannelAccount();
        $fileName = '';
        $report_str = '';
        switch ($report_type) {
            case 'account';
                $report_str = '销售账号利润汇总';
                break;
            case 'seller':
                $report_str = '销售员利润汇总';
                break;
            case 'overseas':
                $report_str = '海外仓利润汇总';
                break;
            case 'local':
                $report_str = '本地仓利润汇总';
                break;
            default:
                throw new Exception('不支持的汇总类型');
        }
        switch ($channel_id) {
            case 0:
                $fileName = 'fba' . $report_str . '报表';
                break;
            case 1:
                $fileName = 'ebay' . $report_str . '报表';
                break;
            case 2:
                $fileName = '亚马逊平台' . $report_str . '报表';
                break;
            case 3:
                $fileName = 'WISH平台' . $report_str . '报表';
                break;
            case 4:
                $fileName = '速卖通平台' . $report_str . '报表';
                break;
            default:
                throw new Exception('不支持的平台');
        }
        //确保文件名称唯一
        $lastID = (new ReportExportFiles())->order('id desc')->value('id');
        $fileName .= ($lastID + 1);
        if (isset($params['channel_id']) && isset($params['account_id']) && $params['account_id'] && $params['channel_id']) {
            $account = $ChannelAccountService->getAccount($params['channel_id'], $params['account_id']);
            $accountCode = param($account, 'code');
            $fileName .= '_' . $accountCode;
        }
        if (isset($params['seller_id']) && $params['seller_id']) {
            $userName = Cache::store('user')->getOneUser($params['seller_id'])['realname'];
            $fileName .= '_' . $userName;
        }
        $start_time = $params['date_b'] ? $params['date_b'] : '';
        $end_time = $params['date_e'] ? $params['date_e'] : '';
        if ($start_time && $end_time) {
            $fileName .= '_' . $start_time . '_' . $end_time;
        } elseif ($start_time) {
            $fileName .= '_' . $start_time;
        } else {
            $fileName .= '_' . $end_time;
        }
        $fileName .= '.xlsx';
        return $fileName;
    }


    /**
     * 申请导出
     * @param $params
     * @return bool
     * @throws Exception
     * @throws \Exception
     */
    public function applyExport($params)
    {
        Db::startTrans();
        try {
            $userId = Common::getUserInfo()->toArray()['user_id'];
            $cacher = Cache::handler();
            $lastApplyTime = $cacher->hget('hash:export_performance_apply', $userId);
            if ($lastApplyTime && time() - $lastApplyTime < 5) {
                throw new Exception('请求过于频繁', 400);
            } else {
                $cacher->hset('hash:export_performance_apply', $userId, time());
            }
            if (!isset($params['report_type']) || trim($params['report_type']) == '') {
                throw new Exception('汇总类型未设置', 400);
            }
            $model = new ReportExportFiles();
            $model->applicant_id = $userId;
            $model->apply_time = time();
            $model->export_file_name = $this->createExportFileName($params, $params['channel_id'], $params['report_type'], $model->applicant_id);
            $model->status = 0;
            if (!$model->save()) {
                throw new Exception('导出请求创建失败', 500);
            }
            $params['file_name'] = $model->export_file_name;
            $params['apply_id'] = $model->id;
            $queuer = new CommonQueuer(PerformanceExportQueue::class);
            $queuer->push($params);
//           $queuer = new PerformanceExportQueue();
//            $queuer->execute($params);
            Db::commit();
            return true;
        } catch (\Exception $ex) {
            Db::rollback();
            if ($ex->getCode()) {
                throw $ex;
            } else {
                Cache::handler()->hset(
                    'hash:report_export_apply',
                    $params['apply_id'] . '_' . time(),
                    $ex->getMessage());
                throw new Exception('导出请求创建失败', 500);
            }
        }
    }

    /**
     * 导出数据至excel文件
     * @param $params
     * @return bool
     * @throws Exception
     */
    public function export($params)
    {
        try {
            ini_set('memory_limit', '1024M');
            $validate = new FileExportValidate();
            if (!$validate->scene('export')->check($params)) {
                throw new Exception($validate->getError());
            }
            $downLoadDir = '/download/performance/';
            $saveDir = ROOT_PATH . 'public' . $downLoadDir;
            if (!is_dir($saveDir) && !mkdir($saveDir, 0777, true)) {
                throw new Exception('导出目录创建失败');
            }
            $fullName = $saveDir . $params['file_name'];

            //创建excel对象
            $writer = new \XLSXWriter();
            foreach ($params as &$param) {
                $param = trim($param);
            }
            switch ($params['channel_id']) {
                case 0:
                    $titleMap = $this->colMap['fba'][$params['report_type']]['title'];
                    $titleData = $this->colMap['fba'][$params['report_type']]['data'];
                    break;
                case 1:
                    $titleMap = $this->colMap['ebay'][$params['report_type']]['title'];
                    $titleData = $this->colMap['ebay'][$params['report_type']]['data'];
                    break;
                case 2:
                    $titleMap = $this->colMap['amazon'][$params['report_type']]['title'];
                    $titleData = $this->colMap['amazon'][$params['report_type']]['data'];
                    break;
                case 3:
                    $titleMap = $this->colMap['wish'][$params['report_type']]['title'];
                    $titleData = $this->colMap['wish'][$params['report_type']]['data'];
                    break;
                case 4:
                    $titleMap = $this->colMap['aliExpress'][$params['report_type']]['title'];
                    $titleData = $this->colMap['aliExpress'][$params['report_type']]['data'];
                    break;
            }
            $title = [];
            foreach ($titleData as $k => $v) {
                array_push($title, $k);
            }

            $titleOrderData = [];
            foreach ($titleMap as $t => $tt) {
                $titleOrderData[$tt['title']] = 'string';
            }
            $writer->writeSheetHeader('Sheet1', $titleOrderData);
            //统计需要导出的数据行
            if ($params['channel_id'] == 0) {
                $count = $this->fbaCount($params);
            } else {
                $count = $this->searchCount($params);
            }
            $pageSize = 10000;
            $loop = ceil($count / $pageSize);
            //分批导出
            for ($i = 0; $i < $loop; $i++) {
                if ($params['channel_id'] == 0) {
                    $data = $this->newData($this->fbaDoSearch($params, $i + 1, $pageSize), $params, $title);
                } elseif ($params['channel_id'] == 2) {
                    $data = $this->newData($this->doSearch($params, $i + 1, $pageSize), $params, $title);
                } else {
                    $data = $this->assemblyData($this->doSearch($params, $i + 1, $pageSize), $params);
                }
                foreach ($data as $k => $v) {
                    $writer->writeSheetRow('Sheet1', $v);
                }
                $writer->writeToFile($fullName);
                if (is_file($fullName)) {
                    $applyRecord = ReportExportFiles::get($params['apply_id']);
                    $applyRecord->exported_time = time();
                    $applyRecord->download_url = $downLoadDir . $params['file_name'];
                    $applyRecord->status = 1;
                    $applyRecord->isUpdate()->save();
                } else {
                    throw new Exception('文件写入失败');
                }
            }
        } catch (\Exception $ex) {
            $applyRecord = ReportExportFiles::get($params['apply_id']);
            $applyRecord->status = 2;
            $applyRecord->error_message = $ex->getMessage();
            $applyRecord->isUpdate()->save();
            Cache::handler()->hset(
                'hash:report_export',
                $params['apply_id'] . '_' . time(),
                '申请id: ' . $params['apply_id'] . ',导出失败:' . $ex->getMessage());
        }
    }

    /**
     * 获取转运费
     * @param int $channel_id
     * @param int $account_id
     * @param array $params
     * @return int $trans_shipping_fee
     * @throws Exception
     */
    public function getTransShippingFee($channel_id, $account_id, $params)
    {
        try {
            $orderPackageModel = new OrderPackage();
            $where['channel_id'] = $channel_id;
            $where['channel_account_id'] = $account_id;
            //增加时间查询条件
            if (isset($params['search_time'])) {
                $params['date_b'] = isset($params['date_b']) ? $params['date_b'] : 0;
                $params['date_e'] = isset($params['date_e']) ? $params['date_e'] : 0;
                switch ($params['search_time']) {
                    case 'shipping_time':
                        $condition = timeCondition($params['date_b'], $params['date_e']);
                        if (!is_array($condition)) {
                            return json(['message' => '日期格式错误'], 400);
                        }
                        if (!empty($condition)) {
                            $where['shipping_time'] = $condition;
                        }
                        break;
                    case 'paid_time':
                        $condition = timeCondition($params['date_b'], $params['date_e']);
                        if (!is_array($condition)) {
                            return json(['message' => '日期格式错误'], 400);
                        }
                        if (!empty($condition)) {
                            $where['pay_time'] = $condition;
                        }
                        break;
                    default:
                        break;
                }
            }
            //当前（平台-账号）的包裹数据
            $packageInfo = $orderPackageModel->field('warehouse_id,shipping_id,package_weight,shipping_time')
                ->where($where)->select();
            $trans_shipping_fee = 0;
            foreach ($packageInfo as $index => $item) {
                $param = [
                    'warehouse_id' => $item['warehouse_id'],    //仓库ID
                    'carrier_id' => Cache::store('shipping')->getShipping($item['shipping_id'])['carrier_id'] ?? 0,  //物流商ID
                    'date' => date('Y-m', $item['shipping_time']),  //包裹发货时间
                    'weight' => $item['package_weight'],    //包裹重量
                ];
                //调用获取转运费的接口
                $amount = (new TransferShippingFee())->transShippingFee($param)['originalTransFee'] ?? 0;
                $trans_shipping_fee += $amount;
            }
            return $trans_shipping_fee;
        } catch (Exception $ex) {
            throw new Exception('获取转运费错误信息：' . $ex->getMessage());
        }
    }

}