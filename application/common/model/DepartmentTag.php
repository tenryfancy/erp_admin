<?php
namespace app\common\model;

use think\Model;


class DepartmentTag extends Model
{
    /**
     * 初始化
     */
    protected function initialize()
    {
        parent::initialize();
    }

    // 标签状态
    const STATUS_ENABLE  = 1;
    const STATUS_DISABLE = 0;
    const STATUS_DEFAULT = 1;
    const STATUS_TXT = [
        self::STATUS_ENABLE   =>  '已启用',
        self::STATUS_DISABLE  =>  '已停用'
    ];

    // 标签类型
    const TYPE_DEFAULT = 0;
    const TYPE_TEX = [
        self::TYPE_DEFAULT => '其他',
    ];

    // 返回状态名称
    public function getStatusTxt($status)
    {
        return self::STATUS_TXT[$status] ?? '';
    }

    // 返回类型名称
    public function getTypeTxt($type)
    {
        return self::TYPE_TEX[$type] ?? '';
    }
}