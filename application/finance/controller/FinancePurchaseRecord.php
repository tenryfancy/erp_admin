<?php
/**
 * Created by Phpstom.
 * User: YangJiafei
 * Date: 2019/4/12
 * Time: 15:22
 */
namespace app\finance\controller;

use app\common\controller\Base;
use app\common\service\Common;
use app\common\service\Excel;
use app\finance\service\FinancePurchaseRecord as FinancePurchaseRecordService;
use app\finance\service\FinancePurchaseRecordExport;
use think\Exception;
use think\Loader;
use think\Request;
use app\common\model\FinancePurchaseRecord as FinancePurchaseRecordModel;

/**
 * @module 财务核算采购单应付款
 * @title 核算单
 * @url /finance-purchase
 * @author YangJiafei
 * @FinancePurchase
 */
class FinancePurchaseRecord extends Base
{

    /**
     * @title 获得核算单列表
     * @url finance-purchase-record
     * @method get
     * @param FinancePurchaseRecordService $service
     * @return \think\response\Json
     */
    public function index(FinancePurchaseRecordService $service)
    {
        $data = [
            'supplier_id'                   => $this->request->param('supplier_id'),
            'supplier_balance_type'         => json_decode($this->request->param('supplier_balance_type'), true),
            'purchase_order_id'             => json_decode($this->request->param('purchase_order_id'), true),
            'warehouse_id'                  => $this->request->param('warehouse_id'),
            'purchaser_id'                  => json_decode($this->request->param('purchaser_id'), true),
            'supply_chain_specialist_id'    => json_decode($this->request->param('supply_chain_specialist'), true),
            'date_b'                        => $this->request->param('date_b'),
            'date_e'                        => $this->request->param('date_e'),
            'page'                          => $this->request->param('page', 1),
            'page_size'                     => $this->request->param('pageSize', 20),
            'sort_field'                    => $this->request->param('sort_field', 1),
            'sort_type'                     => $this->request->param('sort_type', 20),
        ];

        //保存前端参数，解耦前后段字段
        $service->data = $data;
        if ($service->indexSelectValidate() === false) {
            return json(['message' => $service->indexSelectValidateErrorMsg, 500]);
        }

        $where = $service->getWhere();
        try {
            $list = $service->getList($where);
            $result = [
                'data' => $list,
                'page' => $service->page,
                'pageSize' => $service->pageSize,
                'count' => $service->getCount($where),
            ];
            return json($result, 200);
        } catch (Exception $e) {
            return json(['msg' => $e->getMessage()], 500);
        }
    }

    /**
     * @title 获得核算单详情
     * @url /finance-purchase-record/detail
     * @method get
     * @param FinancePurchaseRecordService $service
     * @return \think\response\Json
     */
    public function detail(FinancePurchaseRecordService $service)
    {

        try {
            if ($service->detailValidate() === false) {
                return json(['message' => $service->detailValidateErrorMsg, 500]);
            }
            $detail = $service->getDetail();
            return json(['data' => $detail, 'page' => $service->page, 'pageSize' => $service->pageSize, 'count' => $service->getDetailCount()], 200);
        } catch (Exception $e) {
            return json(['msg' => $e->getMessage()], 500);
        }
    }

    /**
     * @title 采购核算单导出
     * @url /finance-purchase-record/export
     * @method post
     * @param FinancePurchaseRecordExport $service
     * @return \think\response\Json
     * @throws \PHPExcel_Exception
     * @throws \PHPExcel_Reader_Exception
     * @throws \PHPExcel_Writer_Exception
     */
    public function export(FinancePurchaseRecordExport $service)
    {
        //在最外层处理异常
        try {

            $data = [
                'supplier_id'                   => $this->request->param('supplier_id'),
                'supplier_balance_type'         => json_decode($this->request->param('supplier_balance_type'), true),
                'purchase_order_id'             => json_decode($this->request->param('purchase_order_id'), true),
                'warehouse_id'                  => $this->request->param('warehouse_id'),
                'purchaser_id'                  => json_decode($this->request->param('purchaser_id'), true),
                'supply_chain_specialist_id'    => json_decode($this->request->param('supply_chain_specialist'), true),
                'date_b'                        => $this->request->param('date_b'),
                'date_e'                        => $this->request->param('date_e'),
                'page'                          => $this->request->param('page', 1),
                'page_size'                     => $this->request->param('pageSize', 20),
                'sort_field'                    => $this->request->param('sort_field', 1),
                'sort_type'                     => $this->request->param('sort_type', 20),
                'ids' => $this->request->param('ids', json_encode([])),
                'fields' => $this->request->param('fields'),
                'export_type' => $this->request->param('export_type'),
            ];

            $service->data = $data;

            if ($service->paramValidate() === false) {
                return json(['message' => $service->paramValidateErrorMsg, 500]);
            }
            $params = [
                'ids'    => json_decode($this->request->param('ids', json_encode([])), true),
                'fields' => json_decode($this->request->param('fields'), true),
                'export_type' => json_decode($this->request->param('export_type'), true),
            ];
            $service->initParam($params);
            $result  = $service->doExport();

            return json($result, 200);

        } catch (Exception $e) {
            return json(['message' => $e->getMessage()], 500);
        }

    }

    /**
     * @title 采购核算单导出字段
     * @url /finance-purchase-record/export-fields
     * @method get
     */
    public function getExportFields(Request $request)
    {
        try{
            $service = new FinancePurchaseRecordExport;
            $fields = $service->getAllExportFields();
            return json(array_values($fields), 200);
        } catch (Exception $e) {
            return json(['message' => $e->getMessage()], 500);
        }
    }


}