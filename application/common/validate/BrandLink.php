<?php

//品连推送同步 专用验证器
namespace app\common\validate;


use app\common\exception\JsonErrorException;
use app\goods\service\GoodsBrandsLink;
use think\Request;
use think\Validate;

class BrandLink extends Validate
{
    protected $rule = [
        'content' => '',
        'extension' => ''
    ];

    protected $message = [
        'content.require' => '请选择上传文件',
        'extension' => '文件类型参数不能为空',
        'extension.checkExtension' => '文件类型错误，请选择excel文件上传'
    ];

    protected $scene = [
        //导入sku请求参数验证
        'import_request' => [
            'content' => 'require',
            'extension' => 'require|checkExtension'
        ]
    ];

    /**
     * 对于导入请求参数基础验证
     */
    public function goCheckImport()
    {
        $params = Request::instance()->param();
        if (!$this->batch(false)->scene('import_request')->check($params)) {
            $e = new JsonErrorException($this->error);
            throw $e;
        }
        return true;
    }

    protected function checkExtension($value)
    {
        $extension = GoodsBrandsLink::MIME_TYPE;
        return in_array($value, $extension) ? true : false;
    }
}